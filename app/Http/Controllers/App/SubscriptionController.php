<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Admin\Plan;
use App\Models\Tenant;
use App\Services\Commercial\SaasContractService;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    // Renderiza a tela de bloqueio
    public function blocked()
    {
        $tenant = auth()->user()->tenant;
        // Contrato personalizado: paga a cobrança em aberto do ciclo (pode ser antes do vencimento).
        $charge = app(SaasContractService::class)->openChargeFor($tenant);

        if ($tenant->subscriptionBucket() === Tenant::SUBSCRIPTION_ACTIVE && ! $charge) {
            return redirect()->route('app.dashboard');
        }

        if ($tenant->hasCustomContract()) {
            return Inertia::render('Subscription/Blocked', [
                'plans' => [],
                'tenant' => $tenant->only(['id', 'name', 'company']),
                'charge' => $charge ? [
                    'contract_number' => $charge->contract->contract_number,
                    'amount' => (string) $charge->amount,
                    'due_date' => $charge->due_date->toDateString(),
                    'cycle_start' => $charge->cycle_start->toDateString(),
                    'cycle_end' => $charge->cycle_end->toDateString(),
                ] : null,
                'active' => $tenant->subscriptionBucket() === Tenant::SUBSCRIPTION_ACTIVE,
            ]);
        }

        // Busca apenas planos cobraveis, excluindo Trial e planos sem valor.
        $plans = Plan::query()
            ->get()
            ->filter(fn (Plan $plan) => ! $plan->isTrial() && (float) $plan->value > 0)
            ->values();

        return Inertia::render('Subscription/Blocked', [
            'plans' => $plans,
            'tenant' => $tenant,
        ]);
    }

    // Endpoint leve para o Frontend verificar se o pagamento caiu
    public function checkStatus()
    {
        $tenant = auth()->user()->tenant;
        $status = $tenant->persistedSubscriptionStatus();

        // Contrato: só confirma quando a cobrança em aberto for quitada (o acesso pode estar ativo antes).
        if ($tenant->hasCustomContract() && app(SaasContractService::class)->openChargeFor($tenant)) {
            $status = 'pending';
        }

        return response()->json([
            'status' => $status,
        ]);
    }
}
