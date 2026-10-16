<?php

namespace App\Jobs;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * NFS-e automática do plano tradicional para tenant com "Emitir NFS-e para este cliente SaaS":
 * o fato faturado é o pagamento aprovado da renovação (fluxo PIX do plano). Período de referência
 * sugerido pelo próprio pagamento. Uma nota ativa por pagamento (reserva do SaasInvoiceService).
 */
class EmitSaasPaymentInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $paymentId) {}

    public function handle(SaasInvoiceService $invoices): void
    {
        $payment = Payment::query()->find($this->paymentId);
        $tenant = $payment ? Tenant::query()->find($payment->tenant_id) : null;

        if (! $payment || ! $tenant?->saas_invoice_enabled || AdminFiscalDocument::query()->where('payment_id', $payment->id)->exists()) {
            return;
        }

        $context = $invoices->paymentContext($payment);
        if (! $context['suggested_end']) {
            return;
        }

        try {
            $invoices->emit($payment, $context['suggested_start'], $context['suggested_end'], null, null, automatic: true);
        } catch (FiscalValidationException|FiscalEmissionException|SpedyException $exception) {
            FiscalAdminAudit::record('saas.invoice_failed', $tenant->id, $payment, ['error' => $exception->getMessage()]);
        }
    }
}
