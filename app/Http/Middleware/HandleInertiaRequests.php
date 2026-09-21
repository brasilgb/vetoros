<?php

namespace App\Http\Middleware;

use App\Models\Admin\Plan;
use App\Models\Admin\Setting;
use App\Models\App\CashSession;
use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\FiscalSetting;
use App\Models\App\Message;
use App\Models\App\Order;
use App\Models\App\OrderLog;
use App\Models\App\Other;
use App\Models\App\WhatsappMessage;
use App\Models\TenantFeedback;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    private const FEEDBACK_RECOVERY_SLA_DAYS = 3;

    private function personalTaskIndicator(?User $user): ?array
    {
        if (! $user || ! $user->hasPermission('orders')) {
            return null;
        }

        $thresholdDays = Other::communicationFollowUpCooldownDays();
        $paymentFollowUpsEnabled = Other::financeEnabled($user->tenant_id);

        $budgetOrders = Order::query()
            ->where('budget_follow_up_assigned_to', $user->id)
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->where('updated_at', '<=', now()->subDays($thresholdDays))
            ->where(function ($query) {
                $query->whereNull('budget_follow_up_snoozed_until')
                    ->orWhere('budget_follow_up_snoozed_until', '<=', now());
            })
            ->whereDoesntHave('logs', function ($query) {
                $query->where('action', 'budget_follow_up_task_completed')
                    ->whereDate('created_at', now()->toDateString());
            })
            ->get(['id', 'updated_at']);

        $paymentOrders = Order::query()
            ->where('payment_follow_up_assigned_to', $user->id)
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->where('delivery_date', '<=', now()->subDays($thresholdDays))
            ->where(function ($query) {
                $query->whereNull('payment_follow_up_snoozed_until')
                    ->orWhere('payment_follow_up_snoozed_until', '<=', now());
            })
            ->whereRaw(
                '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
            )
            ->whereDoesntHave('logs', function ($query) {
                $query->where('action', 'payment_follow_up_task_completed')
                    ->whereDate('created_at', now()->toDateString());
            })
            ->get(['id', 'updated_at', 'delivery_date']);
        if (! $paymentFollowUpsEnabled) {
            $paymentOrders = collect();
        }

        $criticalBudget = $budgetOrders->filter(fn ($order) => optional($order->updated_at)->diffInDays(now()) >= 10)->count();
        $criticalPayment = $paymentOrders->filter(function ($order) {
            $reference = $order->delivery_date ?? $order->updated_at;

            return $reference && $reference->diffInDays(now()) >= 10;
        })->count();

        $unassignedBudget = Order::query()
            ->whereNull('budget_follow_up_assigned_to')
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->where('updated_at', '<=', now()->subDays($thresholdDays))
            ->where(function ($query) {
                $query->whereNull('budget_follow_up_snoozed_until')
                    ->orWhere('budget_follow_up_snoozed_until', '<=', now());
            })
            ->whereDoesntHave('logs', function ($query) {
                $query->where('action', 'budget_follow_up_task_completed')
                    ->whereDate('created_at', now()->toDateString());
            })
            ->count();

        $unassignedPayment = $paymentFollowUpsEnabled
            ? Order::query()
                ->whereNull('payment_follow_up_assigned_to')
                ->where('service_status', OrderStatus::DELIVERED)
                ->whereNotNull('delivery_date')
                ->where('delivery_date', '<=', now()->subDays($thresholdDays))
                ->where(function ($query) {
                    $query->whereNull('payment_follow_up_snoozed_until')
                        ->orWhere('payment_follow_up_snoozed_until', '<=', now());
                })
                ->whereRaw(
                    '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
                )
                ->whereDoesntHave('logs', function ($query) {
                    $query->where('action', 'payment_follow_up_task_completed')
                        ->whereDate('created_at', now()->toDateString());
                })
                ->count()
            : 0;

        $total = $budgetOrders->count() + $paymentOrders->count();

        return [
            'total' => $total,
            'budget' => $budgetOrders->count(),
            'payment' => $paymentOrders->count(),
            'critical' => $criticalBudget + $criticalPayment,
            'unassigned' => $unassignedBudget + $unassignedPayment,
            'hasTasks' => ($total + $unassignedBudget + $unassignedPayment) > 0,
        ];
    }

    private function commercialPerformanceAlert(?User $user): ?array
    {
        if (! $user || ! $user->hasPermission('orders')) {
            return null;
        }

        $from = now()->subDays(29)->startOfDay();
        $to = now()->endOfDay();

        $budgetLogs = OrderLog::query()
            ->where('action', 'budget_follow_up_sent')
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('order')
            ->with('order')
            ->get();

        $paymentLogs = OrderLog::query()
            ->where('action', 'payment_reminder_sent')
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('order')
            ->with('order.orderPayments')
            ->get();

        $budgetRate = $budgetLogs->count() > 0
            ? round(($budgetLogs->filter(fn ($log) => $log->order && ! in_array((int) $log->order->service_status, [
                OrderStatus::BUDGET_GENERATED,
                OrderStatus::BUDGET_REJECTED,
                OrderStatus::CANCELLED,
            ], true))->count() / $budgetLogs->count()) * 100, 1)
            : 0.0;

        $paymentRate = $paymentLogs->count() > 0
            ? round(($paymentLogs->filter(function ($log) {
                if (! $log->order) {
                    return false;
                }

                $serviceCost = (float) ($log->order->service_cost ?? 0);
                $paid = (float) $log->order->orderPayments->sum('amount');

                return ($serviceCost - $paid) <= 0.009;
            })->count() / $paymentLogs->count()) * 100, 1)
            : 0.0;

        $budgetTarget = Other::budgetConversionTarget($user->tenant_id);
        $paymentTarget = Other::paymentRecoveryTarget($user->tenant_id);

        $hasBudgetData = $budgetLogs->count() > 0;
        $hasPaymentData = $paymentLogs->count() > 0;

        return [
            'hasAlert' => ($hasBudgetData && $budgetRate < $budgetTarget) || ($hasPaymentData && $paymentRate < $paymentTarget),
            'budgetBelowTarget' => $budgetRate < $budgetTarget,
            'paymentBelowTarget' => $paymentRate < $paymentTarget,
            'budgetRate' => $budgetRate,
            'paymentRate' => $paymentRate,
            'budgetTotal' => $budgetLogs->count(),
            'paymentTotal' => $paymentLogs->count(),
        ];
    }

    private function customerFeedbackAlert(?User $user): ?array
    {
        if (! $user || ! $user->hasPermission('reports')) {
            return null;
        }

        $baseQuery = Order::query()
            ->whereNotNull('customer_feedback_submitted_at')
            ->where('customer_feedback_rating', '<=', 3);

        $openQuery = (clone $baseQuery)
            ->where(function ($query) {
                $query
                    ->whereNull('customer_feedback_recovery_status')
                    ->orWhere('customer_feedback_recovery_status', '!=', 'resolved');
            });

        return [
            'hasAlert' => (clone $openQuery)->exists(),
            'total' => (clone $openQuery)->count(),
            'unassigned' => (clone $openQuery)->whereNull('customer_feedback_recovery_assigned_to')->count(),
            'pending' => (clone $openQuery)
                ->where(function ($query) {
                    $query
                        ->whereNull('customer_feedback_recovery_status')
                        ->orWhere('customer_feedback_recovery_status', 'pending');
                })
                ->count(),
            'overdue' => (clone $openQuery)
                ->where('customer_feedback_submitted_at', '<=', now()->subDays(self::FEEDBACK_RECOVERY_SLA_DAYS))
                ->count(),
            'slaDays' => self::FEEDBACK_RECOVERY_SLA_DAYS,
        ];
    }

    private function tenantFeedbackRequest(?User $user): ?array
    {
        if (! $user || ! $user->tenant_id || $user->isRoot()) {
            return null;
        }

        $feedback = TenantFeedback::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('feedback_status', ['pending', 'opened'])
            ->where(function ($query) {
                $query->whereNull('feedback_expires_at')
                    ->orWhere('feedback_expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if (! $feedback) {
            return null;
        }

        return [
            'hasPending' => true,
            'feedbackId' => $feedback->id,
            'source' => $feedback->feedback_source,
            'expiresAt' => $feedback->feedback_expires_at?->toIso8601String(),
            'url' => route('tenant.feedback.show', $feedback->feedback_token),
        ];
    }

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();
        $tenant = $user?->tenant;
        $hasFlashMessage = collect([
            'success',
            'message',
            'error',
            'authorization_error',
            'import_success',
            'import_error',
            'equipment_saved',
            'equipment_updated',
            'equipment_deleted',
            'customer_equipment_saved',
            'contract_print',
        ])->contains(fn (string $key): bool => $request->session()->has($key));
        $flashId = $hasFlashMessage ? (string) Str::uuid() : null;

        $subscription = null;

        if ($user) {
            if ($user->roles === 1) { // ajuste se root for outro valor
                $subscription = [
                    'is_expired' => false,
                    'days_remaining' => null,
                    'plan_name' => 'SaaS Root',
                ];
            } else {
                $subscription = [
                    'is_expired' => $tenant?->expires_at?->isPast() ?? false,
                    'days_remaining' => $tenant?->grace_days_remaining ?? null,
                    'plan_name' => $tenant?->plan?->name ?? 'Nenhum',
                ];
            }
        }

        $otherSetting = null;
        $openCashSession = null;
        $fiscalSetting = null;
        if ($user) {
            $otherSetting = Other::query()->firstOrCreate([
                'tenant_id' => $user->tenant_id,
            ], [
                'enable_finance' => false,
                'enablesales' => false,
                'show_follow_ups_menu' => false,
                'show_tasks_menu' => false,
                'show_commercial_performance_menu' => false,
                'show_quality_menu' => false,
                'print_label_button_after_order_create' => false,
                'automatic_follow_ups_enabled' => false,
                'enable_technician_schedule_notifications' => false,
            ]);
            $openCashSession = CashSession::query()
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();
            if ($user->tenant_id) {
                $fiscalSetting = FiscalSetting::query()->firstOrCreate([
                    'tenant_id' => $user->tenant_id,
                ], [
                    'enabled' => false,
                    'provider' => 'manual',
                    'environment' => 'production',
                    'nfe_enabled' => false,
                    'nfse_enabled' => false,
                    'nfse_mode' => 'national',
                ]);
            }
        }

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user,
                'role' => $user?->roleKey(),
                'permissions' => $user?->permissions() ?? [],
            ],
            'flash' => [
                'id' => $flashId,
                'success' => fn () => $request->session()->get('success'),
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
                'authorization_error' => fn () => $request->session()->get('authorization_error'),
                'import_success' => fn () => $request->session()->get('import_success'),
                'import_error' => fn () => $request->session()->get('import_error'),
                'equipment_saved' => fn () => $request->session()->get('equipment_saved'),
                'equipment_updated' => fn () => $request->session()->get('equipment_updated'),
                'equipment_deleted' => fn () => $request->session()->get('equipment_deleted'),
                'customer_equipment_saved' => fn () => $request->session()->get('customer_equipment_saved'),
                'label_print' => fn () => $request->session()->get('label_print'),
                'contract_print' => fn () => $request->session()->get('contract_print'),
            ],
            'subscription' => $subscription,

            'company' => $user
                ? Company::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->first(['shortname', 'logo', 'companyname', 'cnpj'])
                : null,
            'setting' => $user ? Setting::first(['name', 'logo']) : null,
            'whatsapp' => $user ? WhatsappMessage::first() : null,
            'othersetting' => $otherSetting ? [
                ...$otherSetting->toArray(),
                'enable_finance' => $otherSetting->enable_finance ?? false,
                'enablesales' => $otherSetting->enablesales ?? false,
                'enable_purchases' => $otherSetting->enable_purchases ?? false,
                'show_follow_ups_menu' => $otherSetting->show_follow_ups_menu ?? false,
                'show_quality_menu' => $otherSetting->show_quality_menu ?? false,
                'print_label_button_after_order_create' => $otherSetting->print_label_button_after_order_create ?? false,
                'enable_technician_schedule_notifications' => $otherSetting->enable_technician_schedule_notifications ?? false,
            ] : null,
            'cashier' => $user ? [
                'isOpen' => (bool) $openCashSession,
                'openedAt' => $openCashSession?->opened_at?->toIso8601String(),
            ] : null,
            'fiscalSetting' => $fiscalSetting ? [
                'enabled' => (bool) $fiscalSetting->enabled,
                'provider' => $fiscalSetting->provider,
                'environment' => $fiscalSetting->environment,
                'nfe_enabled' => (bool) $fiscalSetting->nfe_enabled,
                'nfse_enabled' => (bool) $fiscalSetting->nfse_enabled,
                'nfse_mode' => $fiscalSetting->nfse_mode ?? 'national',
                'company_tax_regime' => $fiscalSetting->company_tax_regime,
                'state_registration' => $fiscalSetting->state_registration,
                'municipal_registration' => $fiscalSetting->municipal_registration,
                'service_city_code' => $fiscalSetting->service_city_code,
                'service_list_item' => $fiscalSetting->service_list_item,
                'default_iss_rate' => $fiscalSetting->default_iss_rate,
                'default_nfe_series' => $fiscalSetting->default_nfe_series,
                'default_nfse_series' => $fiscalSetting->default_nfse_series,
            ] : null,
            'performanceAlert' => $this->commercialPerformanceAlert($user),
            'customerFeedbackAlert' => $this->customerFeedbackAlert($user),
            'tenantFeedbackRequest' => $this->tenantFeedbackRequest($user),
            'taskIndicator' => $this->personalTaskIndicator($user),
            'orderStatus' => $user ? Order::where('service_status', OrderStatus::BUDGET_APPROVED)->get() : null,

            'notifications' => $user
                ? Message::where('recipient_id', $user->id)->where('status', '0')->count()
                : 0,

            'equipments' => $user ? Equipment::all() : [],
            'customers' => $user ? Customer::all() : [],

            'technicals' => $user
                ? User::whereIn('roles', [1, 3])->where('status', 1)->get()
                : [],

            'plans' => $tenant ? Plan::all() : [],

            'name' => config('app.name'),

            'quote' => [
                'message' => trim($message),
                'author' => trim($author),
            ],

            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'url' => config('app.url'),
                'location' => $request->url(),
                'query' => $request->query(),
            ],

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') ||
                $request->cookie('sidebar_state') === 'true',

            'app' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
            ],
        ];
    }
}
