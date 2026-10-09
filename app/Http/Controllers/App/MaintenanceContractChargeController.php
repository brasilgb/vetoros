<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\AccountReceivable;
use App\Models\App\AccountReceivablePayment;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Models\App\MaintenanceContract;
use App\Services\AccountReceivablePaymentService;
use App\Services\Fiscal\FiscalDocumentDeliveryService;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Payments\ReceivablePaymentChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cobranças de um contrato de manutenção (VETOR-FISCAL-05): baixa e estorno de recebimentos,
 * NFS-e (emissão, reprocessamento, consulta) e envio ao cliente. Tudo isolado por tenant
 * (vínculos conferidos contra o contrato) e com a permissão financeira do contrato; ações
 * fiscais exigem também a permissão de notas fiscais.
 */
class MaintenanceContractChargeController extends Controller
{
    public const PAYMENT_METHODS = ['pix', 'cartao', 'dinheiro', 'transferencia', 'boleto'];

    public function __construct(
        private readonly AccountReceivablePaymentService $payments,
        private readonly NativeFiscalService $fiscal,
        private readonly FiscalDocumentDeliveryService $deliveries,
        private readonly ReceivablePaymentChannel $channels,
    ) {}

    public function index(MaintenanceContract $maintenance_contract): Response
    {
        $contract = $maintenance_contract;
        Gate::authorize('update', $contract);
        $contract->load('customer:id,name,email,cpfcnpj');

        $receivables = $contract->receivables()
            ->with(['payments.receivedBy:id,name', 'payments.reversedBy:id,name'])
            ->orderByDesc('due_date')
            ->orderByDesc('id')
            ->get();

        $documents = FiscalDocument::query()
            ->where('documentable_type', AccountReceivable::class)
            ->whereIn('documentable_id', $receivables->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('documentable_id');

        $deliveries = FiscalDocumentDelivery::query()
            ->whereIn('fiscal_document_id', $documents->flatten()->pluck('id'))
            ->with('sentBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('fiscal_document_id');

        $canFiscal = Gate::allows('fiscal-documents.access');

        return Inertia::render('app/maintenance-contracts/charges', [
            'contract' => $contract->only(['id', 'contract_number', 'description', 'monthly_amount', 'status', 'auto_issue_invoice', 'auto_send_invoice', 'invoice_competence']) + [
                'customer' => $contract->customer?->only(['id', 'name', 'email', 'cpfcnpj']),
            ],
            'charges' => $receivables->map(fn (AccountReceivable $receivable) => $this->chargePayload(
                $contract,
                $receivable,
                $documents->get($receivable->id, collect())->first(),
                $deliveries,
                $canFiscal,
            ))->values(),
            'history' => $contract->logs()->with('user:id,name')->limit(100)->get()->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'data' => $log->data,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'paymentMethods' => self::PAYMENT_METHODS,
            'canFiscal' => $canFiscal,
            'invoiceBlocker' => $this->fiscal->contractInvoiceBlocker((int) $contract->tenant_id),
        ]);
    }

    public function storePayment(Request $request, MaintenanceContract $maintenance_contract, AccountReceivable $receivable): RedirectResponse
    {
        Gate::authorize('update', $maintenance_contract);
        $this->ensureCharge($maintenance_contract, $receivable);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:500'],
            // Chave gerada a cada confirmação: reenvio, duplo clique ou nova tentativa não duplicam a baixa.
            'request_key' => ['required', 'uuid'],
        ]);

        $payment = $this->payments->register(
            $receivable,
            $validated,
            (int) Auth::id(),
            AccountReceivablePayment::SOURCE_MANUAL,
            'manual:'.$validated['request_key'],
        );
        $receivable->refresh();

        if (! $payment->wasRecentlyCreated) {
            return back()->with('success', 'Este pagamento já estava registrado; nada foi alterado.');
        }

        // Recebimento não emite nota: a NFS-e do ciclo segue a programação fiscal do contrato.
        $message = $receivable->status === AccountReceivable::STATUS_PAID
            ? 'Pagamento registrado. Cobrança quitada.'
            : 'Pagamento parcial registrado. Saldo: R$ '.number_format((float) $receivable->balance_amount, 2, ',', '.').'.';

        return back()->with('success', $message);
    }

    public function reversePayment(Request $request, MaintenanceContract $maintenance_contract, AccountReceivablePayment $payment): RedirectResponse
    {
        Gate::authorize('update', $maintenance_contract);
        $receivable = AccountReceivable::query()->findOrFail($payment->account_receivable_id);
        $this->ensureCharge($maintenance_contract, $receivable);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $result = $this->payments->reverse($payment, $validated['reason'], (int) Auth::id());

        return back()->with('success', $result['has_authorized_invoice']
            ? 'Recebimento estornado; a cobrança voltou a ficar em aberto. A NFS-e do ciclo continua válida (cancelamento só pelo fluxo fiscal, se a contabilidade orientar).'
            : 'Recebimento estornado; a cobrança voltou a ficar em aberto.');
    }

    public function emitInvoice(MaintenanceContract $maintenance_contract, AccountReceivable $receivable): RedirectResponse
    {
        Gate::authorize('update', $maintenance_contract);
        Gate::authorize('fiscal-documents.access');
        $this->ensureCharge($maintenance_contract, $receivable);

        try {
            // Ação manual: permitida antes da quitação quando a obrigação fiscal exigir.
            $document = $this->fiscal->emitForContractReceivable($receivable, (int) Auth::id(), requirePaid: false);
        } catch (FiscalValidationException|FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return match ($document->status) {
            FiscalDocument::STATUS_AUTHORIZED => back()->with('success', 'NFS-e autorizada.'),
            FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED => back()->with('error', 'NFS-e recusada: '.$document->error_message),
            default => back()->with('success', 'NFS-e enviada para autorização. O resultado aparecerá em instantes.'),
        };
    }

    public function sendInvoice(MaintenanceContract $maintenance_contract, FiscalDocument $fiscalDocument): RedirectResponse
    {
        Gate::authorize('update', $maintenance_contract);
        Gate::authorize('fiscal-documents.access');

        $receivable = $fiscalDocument->documentable_type === AccountReceivable::class
            ? AccountReceivable::query()->find($fiscalDocument->documentable_id)
            : null;
        abort_unless($receivable, 404);
        $this->ensureCharge($maintenance_contract, $receivable);

        try {
            $delivery = $this->deliveries->send($fiscalDocument, FiscalDocumentDelivery::ORIGIN_MANUAL, (int) Auth::id());
        } catch (FiscalEmissionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $delivery->status === FiscalDocumentDelivery::STATUS_SENT
            ? back()->with('success', 'Nota fiscal enviada para '.$delivery->email.'.')
            : back()->with('error', 'Nota não enviada: '.$delivery->error);
    }

    /** A cobrança precisa ser deste contrato (e, pelo escopo de tenant, da mesma empresa). */
    private function ensureCharge(MaintenanceContract $contract, AccountReceivable $receivable): void
    {
        abort_unless(
            $receivable->isMaintenanceContract()
            && (int) $receivable->source_id === (int) $contract->id
            && (int) $receivable->tenant_id === (int) $contract->tenant_id,
            404,
        );
    }

    /**
     * Situações separadas por processo (VETOR-FISCAL-05.3): pagamento ≠ NFS-e ≠ envio.
     *
     * - payment_state: pay | pay_balance | awaiting_automatic | paid | cancelled
     * - fiscal_state: not_issued | scheduled | processing | contingency | authorized | rejected | denied | failed | cancelled
     * - delivery_state: none | awaiting_authorization | pending | sent | failed
     */
    private function chargePayload(MaintenanceContract $contract, AccountReceivable $receivable, ?FiscalDocument $document, $deliveries, bool $canFiscal): array
    {
        $documentDeliveries = $document ? $deliveries->get($document->id, collect()) : collect();
        $lastDelivery = $documentDeliveries->first();
        $files = $canFiscal && $document && in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true);
        $automaticChannel = $receivable->status === AccountReceivable::STATUS_PAID ? null : $this->channels->automaticChannelFor($receivable);
        $scheduled = ! $document
            && $contract->auto_issue_invoice
            && $contract->status === MaintenanceContract::STATUS_ACTIVE
            && $receivable->status !== AccountReceivable::STATUS_CANCELLED
            && $receivable->fiscal_scheduled_for !== null
            && $contract->auto_issue_enabled_at !== null
            && $receivable->fiscal_scheduled_for->gte($contract->auto_issue_enabled_at->copy()->startOfDay());

        return [
            'payment_state' => match (true) {
                $receivable->status === AccountReceivable::STATUS_CANCELLED => 'cancelled',
                $receivable->status === AccountReceivable::STATUS_PAID => 'paid',
                $automaticChannel !== null => 'awaiting_automatic',
                $receivable->status === AccountReceivable::STATUS_PARTIAL => 'pay_balance',
                default => 'pay',
            },
            'automatic_channel' => $automaticChannel,
            'overdue' => ! in_array($receivable->status, [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED], true)
                && $receivable->due_date !== null
                && $receivable->due_date->lt(today()),
            'competence' => ($receivable->competence_start ?? $receivable->due_date)?->format('m/Y'),
            'fiscal_scheduled_for' => $receivable->fiscal_scheduled_for?->toDateString(),
            'fiscal_state' => $document?->status ?? ($scheduled ? 'scheduled' : 'not_issued'),
            'delivery_state' => match (true) {
                $lastDelivery !== null => $lastDelivery->status,
                $document?->status === FiscalDocument::STATUS_AUTHORIZED => 'pending',
                $contract->auto_send_invoice && ($scheduled || in_array($document?->status, [...FiscalDocument::PENDING_STATUSES], true)) => 'awaiting_authorization',
                default => 'none',
            },
            'id' => $receivable->id,
            'description' => $receivable->description,
            'due_date' => $receivable->due_date?->toDateString(),
            'total_amount' => (float) $receivable->total_amount,
            'paid_amount' => (float) $receivable->paid_amount,
            'balance_amount' => (float) $receivable->balance_amount,
            'status' => $receivable->status,
            'last_paid_at' => $receivable->last_paid_at?->toIso8601String(),
            'payments' => $receivable->payments->map(fn (AccountReceivablePayment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'payment_method' => $payment->payment_method,
                'source' => $payment->source,
                'received_by' => $payment->receivedBy?->name,
                'notes' => $payment->notes,
                'reversed_at' => $payment->reversed_at?->toIso8601String(),
                'reversed_by' => $payment->reversedBy?->name,
                'reversal_reason' => $payment->reversal_reason,
            ])->values(),
            'invoice' => $document ? [
                'id' => $document->id,
                'status' => $document->status,
                'number' => $document->number,
                'provider_reference' => $document->provider_reference,
                'access_key' => $document->access_key,
                'issued_at' => $document->issued_at?->toIso8601String(),
                'error_message' => $document->error_message,
                'pdf_url' => $files ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']) : null,
                'xml_url' => $files ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'xml']) : null,
                'can_refresh' => $canFiscal && in_array($document->status, [...FiscalDocument::PENDING_STATUSES, FiscalDocument::STATUS_REJECTED], true),
                'can_send' => $canFiscal && $document->status === FiscalDocument::STATUS_AUTHORIZED,
                'delivery' => $lastDelivery ? [
                    'status' => $lastDelivery->status,
                    'email' => $lastDelivery->email,
                    'origin' => $lastDelivery->origin,
                    'error' => $lastDelivery->error,
                    'sent_by' => $lastDelivery->sentBy?->name,
                    'created_at' => $lastDelivery->created_at?->toIso8601String(),
                ] : null,
                'deliveries_count' => $documentDeliveries->count(),
            ] : null,
            'can_emit' => $canFiscal
                && $receivable->status !== AccountReceivable::STATUS_CANCELLED
                && (! $document || in_array($document->status, [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_FAILED, FiscalDocument::STATUS_CANCELLED], true)),
        ];
    }
}
