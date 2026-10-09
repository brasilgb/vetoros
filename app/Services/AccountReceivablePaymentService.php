<?php

namespace App\Services;

use App\Jobs\EmitMaintenanceContractInvoice;
use App\Models\App\AccountReceivable;
use App\Models\App\AccountReceivablePayment;
use App\Models\App\CashSession;
use App\Models\App\CashSessionMovement;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContractLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Baixa de contas a receber (VETOR-FISCAL-05). Usa a própria `accounts_receivable` e o caixa
 * existente: não é um financeiro paralelo.
 *
 * - Baixa manual exige caixa aberto e vira uma entrada do caixa (como o pagamento local do técnico).
 * - Idempotente pela referência: na baixa manual, a chave da confirmação ("Pagamento efetuado");
 *   no evento integrado (provedores futuros), o id do provedor. Repetir a mesma referência devolve
 *   o recebimento já gravado. A autenticidade do evento integrado é de quem chama (webhook validado).
 * - Estorno nunca apaga: marca o recebimento, ajusta o caixa e recalcula a conta.
 * - A NFS-e automática do contrato só é disparada quando a conta fica integralmente quitada.
 */
class AccountReceivablePaymentService
{
    public const CASH_SOURCE = 'account_receivable_payment';

    public function __construct(private readonly CashSessionService $cashSessions) {}

    /**
     * @param  array{amount: float|int|string, paid_at?: string|null, payment_method: string, notes?: string|null}  $data
     */
    public function register(
        AccountReceivable $receivable,
        array $data,
        ?int $userId,
        string $source = AccountReceivablePayment::SOURCE_MANUAL,
        ?string $externalReference = null,
    ): AccountReceivablePayment {
        if ($source === AccountReceivablePayment::SOURCE_INTEGRATION && blank($externalReference)) {
            throw new \InvalidArgumentException('Evento integrado sem referência externa.');
        }

        if ($existing = $this->findByReference((int) $receivable->tenant_id, $source, $externalReference)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($receivable, $data, $userId, $source, $externalReference) {
                $locked = AccountReceivable::query()->withoutGlobalScopes()->whereKey($receivable->getKey())->lockForUpdate()->firstOrFail();

                if ($existing = $this->findByReference((int) $locked->tenant_id, $source, $externalReference)) {
                    return $existing;
                }

                $amount = round((float) $data['amount'], 2);
                $this->assertPayable($locked, $amount);

                $cashSession = null;
                if ($source === AccountReceivablePayment::SOURCE_MANUAL) {
                    $cashSession = CashSession::query()->withoutGlobalScopes()
                        ->where('tenant_id', $locked->tenant_id)
                        ->where('status', 'open')
                        ->latest('opened_at')
                        ->first();

                    if (! $cashSession) {
                        throw ValidationException::withMessages(['amount' => 'Abra o caixa diário antes de registrar o recebimento.']);
                    }
                }

                $payment = AccountReceivablePayment::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'account_receivable_id' => $locked->id,
                    'amount' => $amount,
                    'paid_at' => filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at']) : now(),
                    'payment_method' => $data['payment_method'],
                    'source' => $source,
                    'external_reference' => $externalReference,
                    'received_by' => $userId,
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($cashSession) {
                    $movement = $this->cashSessions->registerEntry($cashSession, [
                        'amount' => $amount,
                        'description' => 'Recebimento: '.($locked->description ?: 'conta a receber #'.$locked->id),
                        'source_type' => self::CASH_SOURCE,
                        'source_id' => $payment->id,
                    ], (int) $userId);
                    $payment->forceFill(['cash_session_movement_id' => $movement->id])->save();
                }

                $wasPaid = $locked->status === AccountReceivable::STATUS_PAID;
                $this->recalculate($locked);

                $this->contractLog($locked, $userId, 'payment_registered', [
                    'account_receivable_id' => $locked->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'source' => $source,
                    'status' => $locked->status,
                ]);

                if (! $wasPaid && $locked->status === AccountReceivable::STATUS_PAID) {
                    $this->queueAutomaticInvoice($locked, $userId);
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Dois eventos iguais ao mesmo tempo: o segundo devolve o recebimento já gravado.
            if ($existing = $this->findByReference((int) $receivable->tenant_id, $source, $externalReference)) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Estorna um recebimento. Se a conta já tinha NFS-e autorizada, a nota não é cancelada
     * automaticamente: o retorno indica que ela precisa de revisão (cancelamento é decisão fiscal).
     *
     * @return array{payment: AccountReceivablePayment, invoice_review_required: bool}
     */
    public function reverse(AccountReceivablePayment $payment, string $reason, ?int $userId): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo do estorno (mínimo de 5 caracteres).']);
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            $receivable = AccountReceivable::query()->withoutGlobalScopes()->whereKey($payment->account_receivable_id)->lockForUpdate()->firstOrFail();
            $payment = AccountReceivablePayment::query()->withoutGlobalScopes()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw ValidationException::withMessages(['reason' => 'Este recebimento já foi estornado.']);
            }

            $this->reverseCash($payment, $reason, $userId);

            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ])->save();

            $this->recalculate($receivable);

            $invoiceReview = $receivable->fiscalDocuments()->withoutGlobalScopes()
                ->where('status', FiscalDocument::STATUS_AUTHORIZED)
                ->exists();

            $this->contractLog($receivable, $userId, 'payment_reversed', [
                'account_receivable_id' => $receivable->id,
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'reason' => $reason,
                'status' => $receivable->status,
                'invoice_review_required' => $invoiceReview,
            ]);

            return ['payment' => $payment, 'invoice_review_required' => $invoiceReview];
        });
    }

    /** Recalcula valor pago, saldo e situação a partir dos recebimentos não estornados. */
    public function recalculate(AccountReceivable $receivable): AccountReceivable
    {
        $active = AccountReceivablePayment::query()->withoutGlobalScopes()
            ->where('account_receivable_id', $receivable->id)
            ->whereNull('reversed_at');

        $paid = round((float) (clone $active)->sum('amount'), 2);
        $total = round((float) $receivable->total_amount, 2);
        $last = (clone $active)->orderByDesc('paid_at')->orderByDesc('id')->first();

        $receivable->forceFill([
            'paid_amount' => $paid,
            'balance_amount' => max(0, round($total - $paid, 2)),
            'status' => match (true) {
                $receivable->status === AccountReceivable::STATUS_CANCELLED => AccountReceivable::STATUS_CANCELLED,
                $paid <= 0 => AccountReceivable::STATUS_PENDING,
                $paid + 0.009 >= $total => AccountReceivable::STATUS_PAID,
                default => AccountReceivable::STATUS_PARTIAL,
            },
            'last_paid_at' => $last?->paid_at,
            'payment_method' => $last?->payment_method,
        ])->save();

        return $receivable;
    }

    private function assertPayable(AccountReceivable $receivable, float $amount): void
    {
        if ($receivable->status === AccountReceivable::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['amount' => 'Esta cobrança está cancelada.']);
        }

        $balance = round((float) $receivable->total_amount - (float) $receivable->paid_amount, 2);

        if ($balance <= 0) {
            throw ValidationException::withMessages(['amount' => 'Esta cobrança já está quitada.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        if ($amount > $balance + 0.009) {
            throw ValidationException::withMessages(['amount' => 'O valor não pode ser maior que o saldo da cobrança (R$ '.number_format($balance, 2, ',', '.').').']);
        }
    }

    /**
     * Desfaz o efeito no caixa: cancela a entrada se o caixa dela ainda estiver aberto; se já
     * foi fechado, registra a devolução como saída no caixa aberto atual.
     */
    private function reverseCash(AccountReceivablePayment $payment, string $reason, ?int $userId): void
    {
        if (! $payment->cash_session_movement_id) {
            return;
        }

        $movement = CashSessionMovement::query()->withoutGlobalScopes()->lockForUpdate()->find($payment->cash_session_movement_id);

        if (! $movement || $movement->cancelled_at) {
            return;
        }

        $session = CashSession::query()->withoutGlobalScopes()->find($movement->cash_session_id);

        if ($session && $session->status === 'open') {
            $movement->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => 'Estorno do recebimento: '.$reason,
            ])->save();

            return;
        }

        $current = CashSession::query()->withoutGlobalScopes()
            ->where('tenant_id', $payment->tenant_id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (! $current) {
            throw ValidationException::withMessages(['reason' => 'O caixa desse recebimento já foi fechado. Abra o caixa para registrar a devolução do valor.']);
        }

        try {
            $this->cashSessions->registerWithdrawal($current, [
                'amount' => (float) $payment->amount,
                'description' => 'Estorno de recebimento #'.$payment->id.': '.$reason,
            ], (int) $userId);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
    }

    private function queueAutomaticInvoice(AccountReceivable $receivable, ?int $userId): void
    {
        $contract = $receivable->maintenanceContract();

        if (! $contract?->auto_issue_invoice) {
            return;
        }

        $this->contractLog($receivable, $userId, 'invoice_queued', ['account_receivable_id' => $receivable->id]);
        EmitMaintenanceContractInvoice::dispatch($receivable->id)->afterCommit();
    }

    private function findByReference(int $tenantId, string $source, ?string $externalReference): ?AccountReceivablePayment
    {
        if (blank($externalReference)) {
            return null;
        }

        return AccountReceivablePayment::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', $source)
            ->where('external_reference', $externalReference)
            ->first();
    }

    private function contractLog(AccountReceivable $receivable, ?int $userId, string $action, array $data): void
    {
        if (! $receivable->isMaintenanceContract()) {
            return;
        }

        MaintenanceContractLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $receivable->tenant_id,
            'maintenance_contract_id' => $receivable->source_id,
            'user_id' => $userId,
            'action' => $action,
            'data' => $data,
        ]);
    }
}
