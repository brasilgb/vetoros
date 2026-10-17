<?php

namespace App\Services\Fiscal;

use App\Models\App\AccountReceivable;
use App\Models\App\AccountReceivablePayment;
use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Other;
use App\Models\App\StandaloneInvoice;
use App\Services\AccountReceivablePaymentService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emissão fiscal avulsa (VETOR-FISCAL-AVULSA-01), direto da tela Notas Fiscais.
 *
 * - Origem própria (`standalone_invoices`): não simula OS, venda ou contrato, não movimenta
 *   estoque e não toca os documentos fiscais existentes.
 * - Idempotente pela chave da solicitação: reenviar a mesma chave devolve a solicitação já
 *   gravada, sem nova nota, nova conta ou novo recebimento.
 * - Validação fiscal antes de gravar: liberações do tenant (NativeFiscalService::blocker) e o
 *   mesmo payload que vai à Spedy. Com problema, nada é gravado.
 * - Financeiro opcional, na mesma transação da solicitação: conta a receber com vencimento ou
 *   conta já quitada por recebimento no caixa (AccountReceivablePaymentService, que exige caixa
 *   aberto). A autorização da nota nunca dá baixa: pagamento e nota são processos separados.
 * - A emissão roda depois do commit; rejeição, falha e timeout ficam no documento e são
 *   reprocessados com o mesmo integrationId (NativeFiscalService::emitStandalone).
 */
class StandaloneInvoiceService
{
    public function __construct(
        private readonly NativeFiscalService $fiscal,
        private readonly SpedyPayloadBuilder $payloads,
        private readonly AccountReceivablePaymentService $payments,
    ) {}

    /**
     * @param  array{request_key: string, type: string, customer_id?: int|null, description?: string|null, items?: list<array{part_id: int, quantity: float|int|string, unit_price: float|int|string}>, service_amount?: float|int|string|null, service_list_item?: string|null, iss_rate?: float|int|string|null, payment_method?: string|null, finance_mode: string, due_date?: string|null, paid_at?: string|null, notes?: string|null}  $data
     * @return array{invoice: StandaloneInvoice, document: ?FiscalDocument, duplicate: bool}
     */
    public function request(array $data, int $tenantId, ?int $userId, bool $canUseFinance): array
    {
        if ($existing = $this->findByKey($tenantId, $data['request_key'])) {
            return ['invoice' => $existing, 'document' => $this->latestDocument($existing), 'duplicate' => true];
        }

        if ($blocker = $this->fiscal->blocker($tenantId, $data['type'])) {
            throw new FiscalEmissionException($blocker);
        }

        $financeMode = $data['finance_mode'] ?? StandaloneInvoice::FINANCE_NONE;
        if ($financeMode !== StandaloneInvoice::FINANCE_NONE && ! ($canUseFinance && Other::financeEnabled($tenantId))) {
            throw ValidationException::withMessages(['finance_mode' => 'O financeiro não está habilitado para esta conta ou para o seu usuário.']);
        }

        $invoice = $this->buildInvoice($data, $tenantId, $userId);
        $this->validatePayload($invoice);

        try {
            $invoice = DB::transaction(function () use ($invoice, $data, $financeMode, $userId) {
                $invoice->save();

                if ($financeMode !== StandaloneInvoice::FINANCE_NONE) {
                    $this->registerFinance($invoice, $data, $financeMode, $userId);
                }

                return $invoice;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Mesma chave enviada em paralelo: a outra requisição já gravou (e emite) a solicitação.
            if ($existing = $this->findByKey($tenantId, $data['request_key'])) {
                return ['invoice' => $existing, 'document' => $this->latestDocument($existing), 'duplicate' => true];
            }

            throw $exception;
        }

        return ['invoice' => $invoice, 'document' => $this->emit($invoice, $userId), 'duplicate' => false];
    }

    /** Reprocessa a nota rejeitada ou com falha (mesmo integrationId, mesma solicitação). */
    public function reprocess(StandaloneInvoice $invoice, ?int $userId): FiscalDocument
    {
        return $this->fiscal->emitStandalone($invoice, $userId);
    }

    /**
     * Descarta uma solicitação que nunca chegou a ter nota válida (rejeitada ou com falha). A conta
     * a receber em aberto é cancelada; com recebimento ativo o descarte é recusado: o estorno vem
     * antes, pelo fluxo financeiro.
     */
    public function discard(StandaloneInvoice $invoice, ?int $userId): StandaloneInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = StandaloneInvoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($invoice->isDiscarded()) {
                return $invoice;
            }
            if (FiscalDocument::hasNativeHistoryFor($invoice)) {
                throw new FiscalEmissionException('Esta solicitação tem nota em processamento, autorizada ou cancelada e não pode ser descartada. Use o cancelamento da nota.');
            }

            if ($invoice->account_receivable_id) {
                $receivable = AccountReceivable::query()->withoutGlobalScopes()->lockForUpdate()->find($invoice->account_receivable_id);

                if ($receivable) {
                    $activePayments = AccountReceivablePayment::query()->withoutGlobalScopes()
                        ->where('account_receivable_id', $receivable->id)
                        ->whereNull('reversed_at')
                        ->exists();

                    if ($activePayments) {
                        throw new FiscalEmissionException('Esta nota avulsa tem recebimento registrado. Estorne o recebimento antes de descartar a solicitação.');
                    }

                    $receivable->forceFill([
                        'status' => AccountReceivable::STATUS_CANCELLED,
                        'balance_amount' => 0,
                        'notes' => trim(trim((string) $receivable->notes)."\nCancelada com o descarte da nota avulsa #{$invoice->id}."),
                    ])->save();
                }
            }

            $invoice->forceFill([
                'status' => StandaloneInvoice::STATUS_DISCARDED,
                'discarded_at' => now(),
                'discarded_by' => $userId,
            ])->save();

            return $invoice;
        });
    }

    private function emit(StandaloneInvoice $invoice, ?int $userId): FiscalDocument
    {
        try {
            return $this->fiscal->emitStandalone($invoice, $userId);
        } catch (FiscalEmissionException|FiscalValidationException|SpedyException) {
            // A solicitação (e o financeiro) já estão gravados; o documento guarda o erro e a nota
            // é reprocessada pela tela.
            return $this->latestDocument($invoice) ?? throw new FiscalEmissionException('Não foi possível enviar a nota avulsa. Tente reprocessar.');
        }
    }

    private function buildInvoice(array $data, int $tenantId, ?int $userId): StandaloneInvoice
    {
        $service = $data['type'] === SpedyClient::MODEL_NFSE;
        $items = $service ? null : collect($data['items'] ?? [])->map(fn (array $item) => [
            'part_id' => (int) $item['part_id'],
            'quantity' => round((float) $item['quantity'], 4),
            'unit_price' => round((float) $item['unit_price'], 2),
        ])->values()->all();

        $total = $service
            ? round((float) ($data['service_amount'] ?? 0), 2)
            : round((float) collect($items)->sum(fn (array $item) => round($item['quantity'] * $item['unit_price'], 2)), 2);

        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        return new StandaloneInvoice([
            'tenant_id' => $tenantId,
            'request_key' => $data['request_key'],
            'type' => $data['type'],
            'status' => StandaloneInvoice::STATUS_ACTIVE,
            'customer_id' => $data['customer_id'] ?? null,
            'description' => $service ? trim((string) ($data['description'] ?? '')) : null,
            'items' => $items,
            // Tributação aplicada fica gravada: um reprocessamento usa a mesma da solicitação.
            'tax_data' => $service ? [
                'service_list_item' => filled($data['service_list_item'] ?? null) ? trim($data['service_list_item']) : $setting?->service_list_item,
                'iss_rate' => ($data['iss_rate'] ?? null) !== null && $data['iss_rate'] !== '' ? round((float) $data['iss_rate'], 4) : ($setting?->default_iss_rate !== null ? (float) $setting->default_iss_rate : null),
                'taxation_type' => $setting?->nfse_taxation_type,
            ] : null,
            'total_amount' => $total,
            'payment_method' => $data['payment_method'] ?? null,
            'finance_mode' => $data['finance_mode'] ?? StandaloneInvoice::FINANCE_NONE,
            'created_by' => $userId,
        ]);
    }

    /** Monta o payload real (sem enviar) para recusar a solicitação antes de gravar qualquer coisa. */
    private function validatePayload(StandaloneInvoice $invoice): void
    {
        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->firstOrFail();
        $company = Company::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $invoice->tenant_id]);

        $this->payloads->standaloneInvoice($invoice, $company, $setting, 'validacao');
    }

    private function registerFinance(StandaloneInvoice $invoice, array $data, string $mode, ?int $userId): void
    {
        $receivable = AccountReceivable::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'customer_id' => $invoice->customer_id,
            'source_type' => AccountReceivable::SOURCE_STANDALONE_INVOICE,
            'source_id' => $invoice->id,
            'installment_number' => 1,
            'installments_total' => 1,
            'description' => sprintf('Nota avulsa #%d (%s)', $invoice->id, mb_strtoupper($invoice->type)),
            'total_amount' => $invoice->total_amount,
            'paid_amount' => 0,
            'balance_amount' => $invoice->total_amount,
            'due_date' => $mode === StandaloneInvoice::FINANCE_RECEIVED
                ? substr((string) $data['paid_at'], 0, 10)
                : $data['due_date'],
            'status' => AccountReceivable::STATUS_PENDING,
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->forceFill(['account_receivable_id' => $receivable->id])->save();

        if ($mode === StandaloneInvoice::FINANCE_RECEIVED) {
            // Mesmas regras da baixa manual: caixa aberto, data não futura e entrada no caixa.
            $this->payments->register($receivable, [
                'amount' => (float) $invoice->total_amount,
                'paid_at' => $data['paid_at'],
                'payment_method' => $data['payment_method'],
                'notes' => 'Recebimento informado na emissão da nota avulsa #'.$invoice->id,
            ], $userId, AccountReceivablePayment::SOURCE_MANUAL, 'standalone:'.$invoice->request_key);
        }
    }

    private function findByKey(int $tenantId, string $key): ?StandaloneInvoice
    {
        return StandaloneInvoice::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('request_key', $key)
            ->first();
    }

    private function latestDocument(StandaloneInvoice $invoice): ?FiscalDocument
    {
        return FiscalDocument::query()->withoutGlobalScopes()
            ->where('documentable_type', StandaloneInvoice::class)
            ->where('documentable_id', $invoice->id)
            ->latest('id')
            ->first();
    }
}
