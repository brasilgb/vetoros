<?php

namespace App\Jobs;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Services\Commercial\SaasContractService;
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
 * NFS-e programada de um ciclo do contrato SaaS, na data fiscal e independente do pagamento.
 * Idempotente: cobrança que já tem documento não é emitida de novo, e a reserva do
 * SaasInvoiceService recusa uma segunda nota ativa. Falhas ficam auditadas; a cobrança não muda.
 */
class EmitSaasChargeInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $chargeId) {}

    public function handle(SaasInvoiceService $invoices, SaasContractService $contracts): void
    {
        $charge = TenantSubscriptionCharge::query()->with('tenant', 'contract')->find($this->chargeId);

        if (! $charge || AdminFiscalDocument::query()->where('tenant_subscription_charge_id', $charge->id)->exists()) {
            return;
        }

        if (! $contracts->isAutomaticallyInvoiceable($charge)) {
            return;
        }

        try {
            $invoices->emitForCharge($charge);
        } catch (FiscalValidationException|FiscalEmissionException|SpedyException $exception) {
            if (! $contracts->hasActiveDocument($charge)) {
                FiscalAdminAudit::record('saas.invoice_failed', $charge->tenant_id, $charge, ['error' => $exception->getMessage()]);
            }
        }
    }
}
