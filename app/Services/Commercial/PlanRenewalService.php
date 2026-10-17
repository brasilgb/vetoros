<?php

namespace App\Services\Commercial;

use App\Jobs\EmitSaasPaymentInvoice;
use App\Models\Admin\Plan;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\SubscriptionInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renovação do plano tradicional por um pagamento confirmado na Efí (VETOR-SAAS-EFI-02/03).
 * Soma o período do plano a partir do vencimento atual (ou de hoje, se já venceu). O mesmo
 * pagamento nunca renova duas vezes (`tenants.last_payment_id`), e tenant com contrato
 * personalizado não renova por plano (evita competência cobrada duas vezes).
 */
class PlanRenewalService
{
    public const RENEWED = 'renewed';

    public const ALREADY_APPLIED = 'already_applied';

    public const SKIPPED_CUSTOM_CONTRACT = 'skipped_custom_contract';

    public const TENANT_NOT_FOUND = 'tenant_not_found';

    public function __construct(private readonly SubscriptionInvoiceService $subscriptionInvoices) {}

    /** @param  callable(Tenant): void|null  $inTransaction  ajuste extra do registro do pagamento dentro da mesma transação */
    public function renew(int $tenantId, Plan $plan, Payment $record, ?callable $inTransaction = null): string
    {
        $months = $plan->billingMonths();

        $result = DB::transaction(function () use ($tenantId, $plan, $record, $months, $inTransaction) {
            $tenant = Tenant::query()->lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return self::TENANT_NOT_FOUND;
            }

            if ($tenant->hasCustomContract()) {
                Log::warning('Pagamento de plano aprovado para tenant com contrato personalizado; renovação por plano ignorada.', [
                    'payment_id' => (string) $record->payment_id,
                    'tenant_id' => $tenant->id,
                ]);

                return self::SKIPPED_CUSTOM_CONTRACT;
            }

            if ((string) $tenant->last_payment_id === (string) $record->payment_id) {
                return self::ALREADY_APPLIED;
            }

            $startBase = ($tenant->expires_at && $tenant->expires_at->isFuture()) ? $tenant->expires_at->copy() : now();

            $tenant->update([
                'plan_id' => $plan->id,
                'period_id' => $plan->preferredPeriod()?->id,
                'subscription_status' => 'active',
                'expires_at' => $startBase->addMonths($months),
                'last_payment_id' => (string) $record->payment_id,
                'status' => 1,
            ]);

            if ($inTransaction) {
                $inTransaction($tenant);
            }

            Log::info("Tenant {$tenant->name} renovado. Plano: {$plan->name}. Provedor: {$record->gateway}.");

            return self::RENEWED;
        });

        if ($result === self::RENEWED) {
            $this->afterRenewal($record->refresh(), $plan);
        }

        return $result;
    }

    /** NFS-e automática do plano ("Emitir NFS-e para este cliente SaaS") e recibo, fora da transação. */
    private function afterRenewal(Payment $record, Plan $plan): void
    {
        $tenant = Tenant::query()->find($record->tenant_id);
        if (! $tenant) {
            return;
        }

        if ($tenant->saas_invoice_enabled) {
            EmitSaasPaymentInvoice::dispatch($record->id);
        }

        $this->subscriptionInvoices->sendPaidInvoice($record, $tenant, $plan);
    }
}
