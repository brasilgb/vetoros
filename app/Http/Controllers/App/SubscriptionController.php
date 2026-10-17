<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Admin\Plan;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Commercial\SaasContractService;
use App\Services\Payments\SaasPaymentTarget;
use App\Support\SubscriptionAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinatura do tenant (VETOR-SAAS-EFI-04): consulta e pagamento em qualquer estado (ativa, perto do
 * vencimento, vencendo hoje, em carência ou bloqueada). Fica fora do middleware de assinatura, então o
 * cliente bloqueado sempre alcança a tela, o checkout Efí, a consulta do pagamento e o logout.
 */
class SubscriptionController extends Controller
{
    /** Destino do middleware para o tenant bloqueado. Fora do bloqueio, a mesma tela em /subscription. */
    public function blocked(Request $request): Response|RedirectResponse
    {
        $tenant = $request->user()->tenant;
        if (! $tenant) {
            return redirect()->route('admin.dashboard');
        }

        if ($tenant->subscriptionBucket() !== Tenant::SUBSCRIPTION_BLOCKED) {
            return redirect()->route('subscription.show');
        }

        return $this->render($tenant);
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $tenant = $request->user()->tenant;
        if (! $tenant) {
            return redirect()->route('admin.dashboard');
        }

        return $this->render($tenant);
    }

    /**
     * Situação para a tela acompanhar uma transação. `access` vem da cobertura da assinatura, nunca do
     * navegador: pagamento confirmado com outra competência ainda vencida continua `blocked`.
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if (! $tenant) {
            return response()->json(['status' => 'unavailable'], 404);
        }

        $tenant->refresh();
        $access = $tenant->subscriptionBucket();
        $openCharge = app(SaasContractService::class)->openChargeFor($tenant);
        $status = $tenant->persistedSubscriptionStatus();

        // Contrato: só "ativa" quando não há cobrança em aberto (o acesso pode estar ativo antes do vencimento).
        if ($tenant->hasCustomContract() && $openCharge) {
            $status = 'pending';
        }

        $paymentStatus = null;
        if ($request->filled('payment')) {
            $payment = Payment::query()->where('tenant_id', $tenant->id)->find($request->integer('payment'));
            $paymentStatus = $payment?->status;

            $status = match (true) {
                $payment === null => 'pending',
                $payment->isSettled() && $access === Tenant::SUBSCRIPTION_BLOCKED => 'paid_pending_obligations',
                $payment->isSettled() => 'active',
                in_array($payment->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_CANCELLED], true) => $payment->status,
                default => 'pending',
            };
        }

        return response()->json([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'access' => $access,
            'has_open_charge' => $openCharge !== null,
        ]);
    }

    private function render(Tenant $tenant): Response
    {
        $contracts = app(SaasContractService::class);
        $charge = $contracts->openChargeFor($tenant);
        $checkout = SaasCheckoutController::props($tenant);
        $plans = $tenant->hasCustomContract()
            ? collect()
            : Plan::query()->with('periods')->get()->filter(fn (Plan $plan) => ! $plan->isTrial() && (float) $plan->value > 0)->values();

        // Alvo atual para mostrar a transação Efí pendente depois de recarregar a página.
        $pending = null;
        if ($charge) {
            $pending = $this->pendingPayment(SaasPaymentTarget::forCharge($charge));
        } elseif ($tenant->plan_id && $plans->contains('id', $tenant->plan_id)) {
            $pending = $this->pendingPayment(SaasPaymentTarget::forPlan($tenant, $plans->firstWhere('id', $tenant->plan_id)));
        }

        $response = Inertia::render('Subscription/Blocked', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'company' => $tenant->company],
            'status' => $this->status($tenant),
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'value' => number_format((float) $plan->value, 2, '.', ''),
                'billing_months' => $plan->billingMonths(),
                'current' => (int) $plan->id === (int) $tenant->plan_id,
            ])->values(),
            'charge' => $charge ? [
                'contract_number' => $charge->contract->contract_number,
                'amount' => (string) $charge->amount,
                'due_date' => $charge->due_date->toDateString(),
                'cycle_start' => $charge->cycle_start->toDateString(),
                'cycle_end' => $charge->cycle_end->toDateString(),
                'periodicity' => $charge->contract->periodicityLabel(),
            ] : null,
            // Compatibilidade com quem lê `active` (fatura em aberto antes do bloqueio).
            'active' => $tenant->subscriptionBucket() !== Tenant::SUBSCRIPTION_BLOCKED,
            'checkout' => $checkout,
            'pending_payment' => $pending ? SaasCheckoutController::present($pending) : null,
        ])->toResponse(request());

        // A tela muda com o pagamento: sem cache, para não reaparecer bloqueada depois da liberação.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Situação da assinatura em linguagem do cliente (datas e prazos calculados no servidor). */
    private function status(Tenant $tenant): array
    {
        $bucket = $tenant->subscriptionBucket();
        $expiresAt = $tenant->expires_at?->copy()->startOfDay();
        $today = now()->startOfDay();
        $daysUntilDue = $expiresAt ? (int) $today->diffInDays($expiresAt, false) : null;
        $graceLimit = $tenant->subscriptionGraceLimit();

        $state = match (true) {
            $bucket === Tenant::SUBSCRIPTION_BLOCKED => 'blocked',
            $bucket === Tenant::SUBSCRIPTION_GRACE => 'grace',
            $daysUntilDue === 0 => 'due_today',
            $daysUntilDue !== null && $daysUntilDue <= SubscriptionAlert::DUE_SOON_DAYS => 'due_soon',
            default => 'active',
        };

        return [
            'state' => $state,
            'label' => $tenant->subscriptionLabel(),
            'billing_mode' => $tenant->hasCustomContract() ? 'custom' : 'plan',
            'plan_name' => $tenant->plan?->name,
            'expires_at' => $expiresAt?->toDateString(),
            'grace_until' => $graceLimit?->copy()->subDay()->toDateString(),
            'days_until_due' => $daysUntilDue,
            'reason' => match ($state) {
                'blocked' => 'O vencimento passou e a carência de 3 dias terminou. Regularize o pagamento para liberar o acesso.',
                'grace' => 'A assinatura venceu. Durante a carência de 3 dias o acesso continua; depois dela o sistema é bloqueado.',
                'due_today' => 'A assinatura vence hoje.',
                'due_soon' => 'A assinatura vence em breve.',
                default => null,
            },
        ];
    }

    private function pendingPayment(SaasPaymentTarget $target): ?Payment
    {
        return Payment::query()
            ->where('tenant_id', $target->tenant->id)
            ->where('gateway', Payment::GATEWAY_EFI)
            ->where('status', Payment::STATUS_PENDING)
            ->when($target->charge,
                fn ($query) => $query->where('tenant_subscription_charge_id', $target->charge->id),
                fn ($query) => $query->whereNull('tenant_subscription_charge_id')->where('plan_id', $target->plan->id))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }
}
