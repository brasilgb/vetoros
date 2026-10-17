<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\SubscriptionAlert;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Acesso do tenant pela assinatura (vencimento → 3 dias de carência → bloqueio). Perto do vencimento,
 * no dia e na carência o acesso continua e o aviso vai para a tela (VETOR-SAAS-EFI-04). Bloqueado, só
 * passam as rotas de assinatura e pagamento, que ficam fora deste grupo; por isso não há ciclo.
 */
class CheckSubscriptionStatus
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        if (! $tenant) {
            return $next($request);
        }

        $persistedStatus = $tenant->persistedSubscriptionStatus();
        if ($tenant->subscription_status !== $persistedStatus) {
            $tenant->update([
                'subscription_status' => $persistedStatus,
            ]);
        }

        if ($tenant->subscriptionBucket() !== Tenant::SUBSCRIPTION_BLOCKED) {
            // Sempre definido (inclusive null): um aviso anterior nunca sobra em processo longo.
            Inertia::share('subscription_alert', SubscriptionAlert::for($tenant));

            return $next($request);
        }

        if ($request->routeIs('subscription.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        return redirect()->route('subscription.blocked');
    }
}
