<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantRequest;
use App\Mail\SubscriptionInvoicePaidMail;
use App\Mail\SubscriptionStatusMail;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\App\Other;
use App\Models\App\Payment;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TenantController extends Controller
{
    private function formatPlanDurationLabel(int $months): string
    {
        if ($months <= 0) {
            return 'Sem periodo definido';
        }

        if ($months === 1) {
            return '1 mes';
        }

        if ($months === 12) {
            return '12 meses';
        }

        return "{$months} meses";
    }

    private function resolveCurrentPlanPeriodLabel(?Plan $plan): string
    {
        if (! $plan) {
            return 'Sem periodo definido';
        }

        $preferredPeriod = $plan->preferredPeriod();
        if ($preferredPeriod?->name) {
            return $preferredPeriod->name;
        }

        if ($plan->isTrialCycle()) {
            return '14 dias';
        }

        $billingMonths = $plan->billingMonths();
        if ($billingMonths > 0) {
            return $this->formatPlanDurationLabel($billingMonths);
        }

        return 'Sem periodo definido';
    }

    private function resolvePlan(?int $planId): ?Plan
    {
        if (! $planId) {
            return null;
        }

        return Plan::query()->with('periods')->find($planId);
    }

    private function normalizeId(mixed $selectedValue): ?int
    {
        if ($selectedValue === null || $selectedValue === '') {
            return null;
        }

        return (int) $selectedValue;
    }

    private function resolvePeriod(?int $planId, ?int $periodId): ?Period
    {
        $plan = $this->resolvePlan($planId);
        if (! $plan) {
            return null;
        }

        if ($periodId) {
            return Period::query()->where('plan_id', $plan->id)
                ->find($periodId);
        }

        return $plan->preferredPeriod();
    }

    private function resolveExpirationDate(?int $planId, ?int $periodId, ?Tenant $tenant = null): ?Carbon
    {
        $plan = $this->resolvePlan($planId);
        if (! $plan) {
            return null;
        }

        $normalizedPeriodId = $this->resolvePeriod($plan->id, $periodId)?->id;
        if (
            $tenant &&
            (int) $tenant->plan_id === $plan->id &&
            (int) ($tenant->period_id ?? 0) === (int) ($normalizedPeriodId ?? 0) &&
            $tenant->expires_at
        ) {
            return $tenant->expires_at->copy();
        }

        $period = $this->resolvePeriod($planId, $periodId);
        if ($period) {
            $months = $period->billingMonths();
            if ($months > 0) {
                return Carbon::now()->addMonths($months);
            }
        }

        if ($plan->isTrialCycle()) {
            return Carbon::now()->addDays(14);
        }

        $billingMonths = $plan->billingMonths();
        if ($billingMonths > 0) {
            return Carbon::now()->addMonths($billingMonths);
        }

        return Carbon::now()->addDays(30);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->string('search')->value());
        $subscriptionFilter = trim((string) $request->string('subscription_filter')->value());
        $today = Carbon::today();
        $nextWeek = Carbon::today()->addDays(7)->endOfDay();

        $tenantsCollection = Tenant::with([
            'user',
            'plan',
            'period',
            'latestAdminFiscalDocument',
        ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('company', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%");
                });
            })
            ->get()
            ->filter(function (Tenant $tenant) use ($subscriptionFilter, $today, $nextWeek) {
                if ($subscriptionFilter === '') {
                    return true;
                }

                $bucket = $tenant->subscriptionBucket($today);

                return match ($subscriptionFilter) {
                    'active' => $bucket === Tenant::SUBSCRIPTION_ACTIVE,
                    'grace' => $bucket === Tenant::SUBSCRIPTION_GRACE,
                    'blocked' => $bucket === Tenant::SUBSCRIPTION_BLOCKED,
                    'expires_today' => $tenant->expires_at?->isSameDay($today) ?? false,
                    'expires_next_7_days' => $tenant->expires_at?->betweenIncluded($today, $nextWeek) ?? false,
                    'without_plan' => ! $tenant->plan_id,
                    'without_expiration' => ! $tenant->expires_at,
                    default => true,
                };
            })
            ->sortBy(function (Tenant $tenant) use ($today) {
                return sprintf(
                    '%02d-%020d-%s',
                    $tenant->attentionPriority($today),
                    $tenant->expires_at?->timestamp ?? PHP_INT_MAX,
                    mb_strtolower((string) $tenant->company)
                );
            })
            ->values();

        $page = max((int) $request->integer('page', 1), 1);
        $perPage = 11;
        $tenants = new LengthAwarePaginator(
            $tenantsCollection->forPage($page, $perPage)->values(),
            $tenantsCollection->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $tenants->setCollection(
            $tenants->getCollection()
                ->map(function ($tenant) {
                    $tenant->current_plan_period_label = $this->resolveCurrentPlanPeriodLabel($tenant->plan);
                    $tenant->computed_subscription_label = $tenant->subscriptionLabel();
                    $tenant->computed_subscription_bucket = $tenant->subscriptionBucket();

                    if (! $tenant->expires_at) {
                        $tenant->days_remaining = null;
                        $tenant->status_label = 'Sem vencimento definido';

                        return $tenant;
                    }

                    $expires = Carbon::parse($tenant->expires_at)->startOfDay();
                    $daysRemaining = floor(now()->diffInDays($expires, false));

                    if ($daysRemaining > 0) {
                        $status = "{$daysRemaining} dias restantes";
                    } elseif ($daysRemaining === 0) {
                        $status = 'Expira hoje';
                    } else {
                        $status = 'Expirado há '.abs($daysRemaining).' dias';
                    }

                    $tenant->days_remaining = $daysRemaining;
                    $tenant->status_label = $status;

                    return $tenant;
                })
        );

        return Inertia::render('admin/tenants/index', [
            'tenants' => $tenants,
            // Modo Suporte (VETOR-STABILIZE-03): o modal mostra em que ambiente o acesso acontece.
            'environment' => match (app()->environment()) {
                'production' => 'Produção',
                'staging', 'homologation' => 'Homologação',
                'local' => 'Desenvolvimento local',
                default => app()->environment(),
            },
            'filters' => [
                'search' => $search,
                'subscription_filter' => $subscriptionFilter,
                'result_count' => $tenantsCollection->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $plans = Plan::query()->with('periods')->get();

        // Mail::to($request->email)->send(new UserRegisteredMail($user));
        return Inertia::render('admin/tenants/create-tenant', ['plans' => $plans]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(TenantRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $data['plan_id'] = $this->normalizeId($data['plan_id'] ?? null);
        $data['period_id'] = $this->resolvePeriod($data['plan_id'], $this->normalizeId($data['period_id'] ?? null))?->id;
        $data['expires_at'] = $this->resolveExpirationDate($data['plan_id'], $data['period_id']);

        Tenant::create($data);

        return redirect()->route('admin.tenants.index')->with('success', 'Empresa cadastrado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant)
    {
        $plans = Plan::query()->with('periods')->get();
        $loadedTenant = $tenant->load('plan.periods.features', 'period');
        $loadedTenant->current_plan_period_label = $this->resolveCurrentPlanPeriodLabel($loadedTenant->plan);

        // Módulos ligados pelo próprio cliente em "Sistema e módulos" (somente leitura no RootAdmin; sem criar registro).
        $modules = Other::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->first(['enableparts', 'enablesales', 'enable_purchases', 'enable_finance']);

        return Inertia::render('admin/tenants/edit-tenant', [
            'tenant' => $loadedTenant,
            'plans' => $plans,
            'commercial' => TenantCommercialController::pageData($tenant),
            'modules' => $modules ? [
                'parts' => (bool) $modules->enableparts,
                'sales' => (bool) $modules->enablesales,
                'purchases' => (bool) $modules->enable_purchases,
                'finance' => (bool) $modules->enable_finance,
            ] : null,
        ]);
    }

    public function previewSubscriptionEmail(Tenant $tenant, string $scenario)
    {
        $allowedScenarios = ['expires_in_3_days', 'expires_tomorrow', 'expires_today', 'grace', 'blocked'];
        abort_unless(in_array($scenario, $allowedScenarios, true), 404);

        $tenant->load('plan');
        $notice = $tenant->buildSubscriptionNotice($scenario);

        abort_unless($notice, 422, 'Nao foi possivel gerar a previa para este tenant.');

        return new SubscriptionStatusMail($tenant, $notice);
    }

    public function previewSubscriptionInvoiceEmail(Tenant $tenant)
    {
        $tenant->load('plan', 'latestAdminFiscalDocument');
        $plan = $tenant->plan ?? Plan::query()->where('value', '>', 0)->first();

        abort_unless($plan, 422, 'Nao foi possivel gerar a previa sem um plano pago.');

        $payment = Payment::query()
            ->where('tenant_id', $tenant->id)
            ->settled()
            ->latest()
            ->first();

        if (! $payment) {
            $payment = new Payment([
                'tenant_id' => $tenant->id,
                'gateway' => Payment::GATEWAY_EFI,
                'method' => 'pix',
                'payment_id' => 'preview-'.$tenant->id,
                'amount' => (float) ($plan->value ?? 0),
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'idempotency_key' => 'preview-'.$tenant->id,
                'raw_response' => ['preview' => true],
            ]);
            $payment->created_at = now();
            $payment->updated_at = now();
        }

        return new SubscriptionInvoicePaidMail(
            $tenant,
            $plan,
            $payment,
            $tenant->latestAdminFiscalDocument
        );
    }

    public function sendSubscriptionEmail(Tenant $tenant, string $scenario): RedirectResponse
    {
        $allowedScenarios = ['expires_in_3_days', 'expires_tomorrow', 'expires_today', 'grace', 'blocked'];
        abort_unless(in_array($scenario, $allowedScenarios, true), 404);

        $tenant->load('plan');
        $notice = $tenant->buildSubscriptionNotice($scenario);

        if (! $notice) {
            return redirect()
                ->route('admin.tenants.show', ['tenant' => $tenant->id])
                ->with('error', 'Nao foi possivel gerar o e-mail para este tenant.');
        }

        $recipient = trim((string) $tenant->email);
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return redirect()
                ->route('admin.tenants.show', ['tenant' => $tenant->id])
                ->with('error', 'O tenant nao possui um e-mail valido para envio.');
        }

        Mail::to($recipient)->send(new SubscriptionStatusMail($tenant, $notice));

        return redirect()
            ->route('admin.tenants.show', ['tenant' => $tenant->id])
            ->with('success', "E-mail de assinatura enviado com sucesso para {$recipient}.");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Tenant $tenant)
    {
        return redirect()->route('admin.tenants.show', ['tenant' => $tenant->id]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(TenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validated();

        $data['plan_id'] = $this->normalizeId($data['plan_id'] ?? null);
        $data['period_id'] = $this->resolvePeriod($data['plan_id'], $this->normalizeId($data['period_id'] ?? null))?->id;

        // Trava de planos: com contrato personalizado o plano (base de funcionalidades) só muda pela volta ao plano tradicional.
        if ($tenant->hasCustomContract()) {
            if ((int) $data['plan_id'] !== (int) $tenant->plan_id) {
                throw ValidationException::withMessages([
                    'plan_id' => 'Empresa com contrato personalizado: o plano só muda em "Voltar ao plano tradicional", com data efetiva e confirmação.',
                ]);
            }
            $data['period_id'] = $tenant->period_id;
        }

        // Com contrato personalizado o plano é só a base de funcionalidades: o vencimento segue as cobranças do contrato.
        if (! $tenant->hasCustomContract()) {
            $data['expires_at'] = $this->resolveExpirationDate($data['plan_id'], $data['period_id'], $tenant);
        }

        $tenant->update($data);

        return redirect()->route('admin.tenants.show', ['tenant' => $tenant->id])->with('success', 'Empresa atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant)
    {
        // Histórico comercial e financeiro do SaaS não é apagado junto com a empresa.
        if ($tenant->commercialContract()->exists()) {
            return redirect()->route('admin.tenants.show', ['tenant' => $tenant->id])
                ->with('error', 'A empresa tem contrato comercial e cobranças registradas; desative-a em vez de excluir.');
        }

        $tenant->delete();

        return redirect()->route('admin.tenants.index')->with('success', 'Empresa excluída com sucesso!');
    }
}
