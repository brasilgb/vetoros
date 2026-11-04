<?php

namespace App\Console\Commands;

use App\Models\Admin\Plan;
use App\Models\LeadsIntegrationEvent;
use App\Models\LeadsIntegrationSetting;
use App\Models\Tenant;
use App\Services\LeadsIntegration\LeadsIntegrationEvents;
use Illuminate\Console\Command;

/**
 * Registra "teste encerrado sem contratação" para as contas do VetorOS cujo teste venceu e que ainda
 * não contrataram. Só contas vencidas nos últimos 7 dias (não reenvia histórico antigo ao ligar a
 * integração). Não considera contas com pagamento, contrato personalizado ou assinatura ativada.
 * Idempotente: cada conta recebe um único trial_expired.
 */
class ExpireTrialsLeadsIntegration extends Command
{
    protected $signature = 'vetoros:leads-integration-expire-trials';

    protected $description = 'Grava o evento de teste encerrado sem contratação para o VetorLeads';

    public function handle(LeadsIntegrationEvents $events): int
    {
        if (! LeadsIntegrationSetting::current()->apiReady()) {
            return self::SUCCESS;
        }

        $trialPlanIds = Plan::query()->get()->filter(fn (Plan $plan) => $plan->isTrial())->pluck('id');

        if ($trialPlanIds->isEmpty()) {
            return self::SUCCESS;
        }

        Tenant::query()
            ->whereIn('plan_id', $trialPlanIds)
            ->whereNull('last_payment_id')
            ->where(fn ($query) => $query->whereNull('billing_mode')->orWhere('billing_mode', '!=', Tenant::BILLING_CUSTOM))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('expires_at', '>=', now()->subDays(7))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('leads_integration_events')
                ->whereColumn('leads_integration_events.tenant_id', 'tenants.id')
                ->whereIn('leads_integration_events.type', [
                    LeadsIntegrationEvent::TYPE_TRIAL_EXPIRED,
                    LeadsIntegrationEvent::TYPE_SUBSCRIPTION_ACTIVATED,
                ]))
            ->chunkById(200, function ($tenants) use ($events) {
                foreach ($tenants as $tenant) {
                    $events->trialExpired($tenant);
                }
            });

        return self::SUCCESS;
    }
}
