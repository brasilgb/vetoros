<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Order;
use App\Models\App\OrderLog;
use App\Models\App\Other;
use App\Models\User;
use App\Services\FollowUpTaskService;
use App\Support\OrderStatus;
use App\Support\Pagination;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUpTaskService $followUpTaskService) {}

    private const RESPONSE_LABELS = [
        'responded' => 'Cliente respondeu',
        'no_interest' => 'Sem interesse',
        'waiting_piece' => 'Aguardando peça',
        'promised_payment' => 'Prometeu pagar',
    ];

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function authorizeFollowUpManagement(): void
    {
        $user = $this->currentUser();

        abort_unless($user && $user->hasPermission('orders') && ! $user->isTechnician(), 403);
    }

    private function logOrderAction(Order $order, string $action, array $data = []): void
    {
        OrderLog::create([
            'order_id' => $order->id,
            'user_id' => $this->currentUser()?->id,
            'action' => $action,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ]);
    }

    private function scopeColumns(string $scope): array
    {
        return $this->followUpTaskService->scopeColumns($scope);
    }

    private function feedbackRecoveryNextAction(Order $order): array
    {
        $daysPending = $order->customer_feedback_submitted_at?->diffInDays(now()) ?? 0;
        $status = $order->customer_feedback_recovery_status ?: 'pending';

        if (empty($order->customer_feedback_recovery_assigned_to)) {
            return [
                'label' => 'Assumir tratativa',
                'priority' => $daysPending >= 3 ? 'critica' : 'alta',
            ];
        }

        return [
            'label' => $status === 'in_progress' ? 'Retomar contato com cliente' : 'Iniciar tratativa',
            'priority' => $daysPending >= 3 ? 'critica' : 'alta',
        ];
    }

    private function responseLabel(?string $status): ?string
    {
        return $status ? (self::RESPONSE_LABELS[$status] ?? $status) : null;
    }

    private function scopeOrdersQuery($query)
    {
        $user = $this->currentUser();

        if ($user?->isTechnician() && ! $user->canViewAllOrders()) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            });
        }

        return $query;
    }

    private function communicationThresholdDays(): int
    {
        return Other::communicationFollowUpCooldownDays($this->currentUser()?->tenant_id);
    }

    private function financeEnabled(): bool
    {
        return Other::financeEnabled($this->currentUser()?->tenant_id);
    }

    private function latestCommunication(Order $order): ?array
    {
        $log = $order->logs
            ->whereIn('action', ['payment_reminder_sent', 'budget_follow_up_sent'])
            ->sortByDesc('created_at')
            ->first();

        if (! $log) {
            return null;
        }

        $data = is_array($log->data) ? $log->data : [];

        return [
            'action' => $log->action,
            'trigger' => $data['trigger'] ?? null,
            'channel' => $data['channel'] ?? null,
            'recipient' => $data['recipient'] ?? null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function contactLogAction(string $scope): string
    {
        return match ($scope) {
            'budget' => 'budget_follow_up_sent',
            'payment' => 'payment_reminder_sent',
            default => abort(422, 'Escopo de acompanhamento inválido.'),
        };
    }

    private function applyActiveFollowUpRules($query, string $scope)
    {
        $columns = $this->scopeColumns($scope);
        $cooldownCutoff = now()->subDays($this->communicationThresholdDays());

        return $query
            ->whereNull($columns['paused_at'])
            ->where(function ($query) use ($columns) {
                $query
                    ->whereNull($columns['snoozed_until'])
                    ->orWhere($columns['snoozed_until'], '<=', now());
            })
            ->whereDoesntHave('logs', function ($query) use ($columns, $cooldownCutoff, $scope) {
                $query
                    ->whereIn('action', [
                        $this->contactLogAction($scope),
                        $columns['log_task_completed'],
                    ])
                    ->where('created_at', '>=', $cooldownCutoff);
            });
    }

    private function metricsPeriod(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->get('from'))->startOfDay()
            : now()->subDays(29)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse((string) $request->get('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function baseLogQuery(string $action, Carbon $from, Carbon $to, ?string $trigger = null, ?int $userId = null)
    {
        return OrderLog::query()
            ->where('action', $action)
            ->whereBetween('created_at', [$from, $to])
            ->when($trigger, fn ($query) => $query->whereJsonContains('data->trigger', $trigger))
            ->whereHas('order', function ($query) use ($userId) {
                $this->scopeOrdersQuery($query);

                if ($userId) {
                    $query->where('user_id', $userId);
                }
            })
            ->with(['order.orderPayments:id,order_id,amount']);
    }

    /**
     * Busca uma única vez os logs de acompanhamento (orçamento e cobrança) do período,
     * já com a ordem e os pagamentos carregados. Reaproveitado por todos os cálculos
     * de resumo/ranking dentro da mesma requisição, evitando refazer a mesma consulta.
     *
     * @return array{budget: Collection<int, OrderLog>, payment: Collection<int, OrderLog>}
     */
    private function loadPeriodLogs(Carbon $from, Carbon $to): array
    {
        return [
            'budget' => $this->baseLogQuery('budget_follow_up_sent', $from, $to)->get(),
            'payment' => $this->baseLogQuery('payment_reminder_sent', $from, $to)->get(),
        ];
    }

    private function recoveredFromLogs(Collection $logs, string $scope): int
    {
        return $logs->filter(function (OrderLog $log) use ($scope) {
            $order = $log->order;

            if (! $order) {
                return false;
            }

            if ($scope === 'budget') {
                return ! in_array((int) $order->service_status, [
                    OrderStatus::BUDGET_GENERATED,
                    OrderStatus::BUDGET_REJECTED,
                    OrderStatus::CANCELLED,
                ], true);
            }

            $serviceCost = (float) ($order->service_cost ?? 0);
            $paid = (float) $order->orderPayments->sum('amount');

            return ($serviceCost - $paid) <= 0.009;
        })->count();
    }

    private function trendGranularity(Carbon $from, Carbon $to): string
    {
        $days = $from->diffInDays($to) + 1;

        if ($days > 90) {
            return 'monthly';
        }

        if ($days > 31) {
            return 'weekly';
        }

        return 'daily';
    }

    private function buildTrend(Collection $logs, Carbon $from, Carbon $to, string $scope): array
    {
        $granularity = $this->trendGranularity($from, $to);

        $periods = match ($granularity) {
            'monthly' => CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth()),
            'weekly' => CarbonPeriod::create($from->copy()->startOfWeek(), '1 week', $to->copy()->startOfWeek()),
            default => CarbonPeriod::create($from->copy(), '1 day', $to->copy()),
        };

        $data = collect($periods)->map(function (Carbon $periodStart) use ($logs, $granularity, $scope) {
            [$start, $end, $label] = match ($granularity) {
                'monthly' => [$periodStart->copy()->startOfMonth(), $periodStart->copy()->endOfMonth(), $periodStart->format('MM/YYYY')],
                'weekly' => [$periodStart->copy()->startOfWeek(), $periodStart->copy()->endOfWeek(), $periodStart->format('d/m')],
                default => [$periodStart->copy()->startOfDay(), $periodStart->copy()->endOfDay(), $periodStart->format('d/m')],
            };

            $bucket = $logs->filter(fn (OrderLog $log) => $log->created_at?->between($start, $end));
            $contacted = $bucket->count();
            $recovered = $this->recoveredFromLogs($bucket, $scope);

            return [
                'label' => $label,
                'contacted' => $contacted,
                'recovered' => $recovered,
                'rate' => $this->percentage($recovered, $contacted),
            ];
        })->values()->all();

        return [
            'granularity' => $granularity,
            'data' => $data,
        ];
    }

    private function appendFollowUpData(Order $order): Order
    {
        $totalOrder = round((float) ($order->service_cost ?? 0), 2);
        $totalPaid = round((float) ($order->total_paid ?? 0), 2);
        $remaining = round(max(0, $totalOrder - $totalPaid), 2);
        $referenceDate = $order->delivery_date ?? $order->updated_at ?? $order->created_at;
        $daysPending = $referenceDate ? max(0, (int) floor(Carbon::parse($referenceDate)->diffInDays(now(), true))) : 0;

        $order->setAttribute('remaining_amount', $remaining);
        $order->setAttribute('communication_days_pending', $daysPending);
        $order->setAttribute('last_communication', $this->latestCommunication($order));
        $order->setAttribute('budget_follow_up_paused', ! is_null($order->budget_follow_up_paused_at));
        $order->setAttribute('payment_follow_up_paused', ! is_null($order->payment_follow_up_paused_at));
        $order->setAttribute('budget_follow_up_snoozed', $order->budget_follow_up_snoozed_until?->isFuture() ?? false);
        $order->setAttribute('payment_follow_up_snoozed', $order->payment_follow_up_snoozed_until?->isFuture() ?? false);
        $order->setAttribute('budget_follow_up_response_label', $this->responseLabel($order->budget_follow_up_response_status));
        $order->setAttribute('payment_follow_up_response_label', $this->responseLabel($order->payment_follow_up_response_status));
        $order->setAttribute('next_action', $this->nextActionRecommendation($order));
        $order->setAttribute(
            'can_send_budget_follow_up',
            (int) $order->service_status === OrderStatus::BUDGET_GENERATED
                && ! empty($order->customer?->email)
        );
        $order->setAttribute(
            'can_send_payment_reminder',
            $remaining > 0.009
                && ! empty($order->customer?->email)
        );

        return $order;
    }

    private function nextActionRecommendation(Order $order): array
    {
        $remaining = round(max(0, (float) ($order->service_cost ?? 0) - (float) ($order->total_paid ?? 0)), 2);

        if (($order->budget_follow_up_snoozed_until?->isFuture() ?? false) || ($order->payment_follow_up_snoozed_until?->isFuture() ?? false)) {
            return [
                'label' => 'Aguardar data reagendada',
                'priority' => 'normal',
            ];
        }

        if ((bool) $order->budget_follow_up_paused || (bool) $order->payment_follow_up_paused) {
            return [
                'label' => 'Revisar pausa',
                'priority' => 'normal',
            ];
        }

        if ($order->budget_follow_up_response_status === 'waiting_piece') {
            return [
                'label' => 'Acompanhar peça',
                'priority' => 'normal',
            ];
        }

        if ($order->payment_follow_up_response_status === 'promised_payment') {
            return [
                'label' => 'Confirmar pagamento prometido',
                'priority' => 'alta',
            ];
        }

        if ((int) $order->service_status === OrderStatus::BUDGET_GENERATED) {
            return [
                'label' => $order->last_communication ? 'Retomar orçamento' : 'Cobrar orçamento',
                'priority' => ($order->communication_days_pending ?? 0) >= 10 ? 'critica' : 'alta',
            ];
        }

        if ($remaining > 0.009) {
            return [
                'label' => $order->last_communication ? 'Cobrar saldo novamente' : 'Cobrar saldo',
                'priority' => ($order->communication_days_pending ?? 0) >= 10 ? 'critica' : 'alta',
            ];
        }

        return [
            'label' => 'Acompanhar resposta do cliente',
            'priority' => 'normal',
        ];
    }

    private function dailyAgenda(Collection $budgetOrders, Collection $paymentOrders, Collection $feedbackOrders, ?int $limit = 8): array
    {
        $mapAgenda = function (Collection $orders, string $scope, string $label) {
            $columns = $this->scopeColumns($scope);

            return $orders
                ->reject(function (Order $order) use ($columns) {
                    $snoozedUntil = $order->{$columns['snoozed_until']};

                    if ($snoozedUntil instanceof Carbon && $snoozedUntil->isFuture()) {
                        return true;
                    }

                    return $order->logs
                        ->where('action', $columns['log_task_completed'])
                        ->contains(fn (OrderLog $log) => $log->created_at?->isToday());
                })
                ->map(function (Order $order) use ($label, $scope, $columns) {
                    $priority = $order->next_action['priority'] ?? 'normal';

                    return [
                        'id' => $order->id,
                        'scope' => $scope,
                        'order_number' => $order->order_number,
                        'customer' => $order->customer?->name,
                        'technician' => $order->user?->name ?? 'Não definido',
                        'assigned_to' => match ($scope) {
                            'budget' => $order->budgetFollowUpAssignee?->name,
                            'payment' => $order->paymentFollowUpAssignee?->name,
                        },
                        'assigned_to_id' => match ($scope) {
                            'budget' => $order->budget_follow_up_assigned_to,
                            'payment' => $order->payment_follow_up_assigned_to,
                        },
                        'type' => $label,
                        'days_pending' => (int) ($order->communication_days_pending ?? 0),
                        'next_action' => $order->next_action,
                        'priority' => $priority,
                        'snoozed_until' => optional($order->{$columns['snoozed_until']})->toIso8601String(),
                    ];
                });
        };

        $agenda = $mapAgenda($budgetOrders, 'budget', 'orçamento')
            ->concat($mapAgenda($paymentOrders, 'payment', 'cobrança'))
            ->concat(
                $feedbackOrders->map(function (Order $order) {
                    $nextAction = $this->feedbackRecoveryNextAction($order);

                    return [
                        'id' => $order->id,
                        'scope' => 'feedback',
                        'order_number' => $order->order_number,
                        'customer' => $order->customer?->name,
                        'technician' => $order->user?->name ?? 'Não definido',
                        'assigned_to' => $order->customerFeedbackRecoveryAssignee?->name,
                        'assigned_to_id' => $order->customer_feedback_recovery_assigned_to,
                        'type' => 'insatisfação',
                        'days_pending' => (int) ($order->customer_feedback_submitted_at?->diffInDays(now()) ?? 0),
                        'next_action' => $nextAction,
                        'priority' => $nextAction['priority'] ?? 'normal',
                        'rating' => (int) ($order->customer_feedback_rating ?? 0),
                        'comment' => $order->customer_feedback_comment,
                        'recovery_status' => $order->customer_feedback_recovery_status ?: 'pending',
                    ];
                })
            )
            ->sortByDesc(fn (array $item) => match ($item['next_action']['priority'] ?? 'normal') {
                'critica' => 3,
                'alta' => 2,
                default => 1,
            } * 100 + ($item['days_pending'] ?? 0));

        if (! is_null($limit)) {
            $agenda = $agenda->take($limit);
        }

        return $agenda->values()->all();
    }

    private function baseQuery(Request $request)
    {
        $query = $this->scopeOrdersQuery(
            Order::query()
                ->with([
                    'customer:id,name,email,whatsapp,phone',
                    'equipment:id,equipment',
                    'user:id,name',
                    'budgetFollowUpAssignee:id,name',
                    'paymentFollowUpAssignee:id,name',
                    'customerFeedbackRecoveryAssignee:id,name',
                    'logs',
                ])
                ->withSum('orderPayments as total_paid', 'amount')
                ->orderByDesc('updated_at')
        );

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', $search)
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('cpfcnpj', 'like', "%{$search}%");
                    });
            });
        }

        if ($technicianId = $request->get('technician_id')) {
            $query->where('user_id', $technicianId);
        }

        return $query;
    }

    private function applyResponseFilter($query, Request $request, string $scope)
    {
        $filter = $request->get('response_status');

        if (! $filter || $filter === 'all') {
            return $query;
        }

        $columns = $this->scopeColumns($scope);

        if ($filter === 'none') {
            return $query->whereNull($columns['response_status']);
        }

        return $query->where($columns['response_status'], $filter);
    }

    private function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0;
        }

        return round(($part / $total) * 100, 1);
    }

    private function recoverySummary(array $periodLogs): array
    {
        $budgetLogs = $periodLogs['budget'];
        $paymentLogs = $periodLogs['payment'];

        $budgetContacted = $budgetLogs->count();
        $budgetRecovered = $this->recoveredFromLogs($budgetLogs, 'budget');
        $paymentContacted = $paymentLogs->count();
        $paymentRecovered = $this->recoveredFromLogs($paymentLogs, 'payment');

        return [
            'budget' => [
                'contacted' => $budgetContacted,
                'recovered' => $budgetRecovered,
                'rate' => $this->percentage($budgetRecovered, $budgetContacted),
            ],
            'payment' => [
                'contacted' => $paymentContacted,
                'recovered' => $paymentRecovered,
                'rate' => $this->percentage($paymentRecovered, $paymentContacted),
            ],
        ];
    }

    private function triggerPerformanceSummary(array $periodLogs): array
    {
        $build = function (Collection $logs, string $scope): array {
            $result = [];

            foreach (['manual', 'automatic'] as $trigger) {
                $triggerLogs = $logs->filter(fn (OrderLog $log) => ($log->data['trigger'] ?? null) === $trigger)->values();
                $contacted = $triggerLogs->count();
                $recovered = $this->recoveredFromLogs($triggerLogs, $scope);

                $result[$trigger] = [
                    'contacted' => $contacted,
                    'recovered' => $recovered,
                    'rate' => $this->percentage($recovered, $contacted),
                ];
            }

            return $result;
        };

        return [
            'budget' => $build($periodLogs['budget'], 'budget'),
            'payment' => $build($periodLogs['payment'], 'payment'),
        ];
    }

    private function technicianSummary(Collection $budgetOrders, Collection $paymentOrders): array
    {
        return $budgetOrders
            ->map(function (Order $order) {
                return [
                    'key' => 'budget',
                    'user_id' => $order->user?->id,
                    'name' => $order->user?->name ?? 'Não definido',
                    'paused' => (bool) $order->budget_follow_up_paused,
                    'no_contact' => ! $order->last_communication,
                ];
            })
            ->concat(
                $paymentOrders->map(function (Order $order) {
                    return [
                        'key' => 'payment',
                        'user_id' => $order->user?->id,
                        'name' => $order->user?->name ?? 'Não definido',
                        'paused' => (bool) $order->payment_follow_up_paused,
                        'no_contact' => ! $order->last_communication,
                    ];
                })
            )
            ->groupBy(fn (array $item) => $item['user_id'] ?? 'unassigned')
            ->map(function (Collection $items) {
                $first = $items->first();

                return [
                    'user_id' => $first['user_id'],
                    'name' => $first['name'],
                    'budget_follow_ups' => $items->where('key', 'budget')->count(),
                    'payment_follow_ups' => $items->where('key', 'payment')->count(),
                    'paused' => $items->where('paused', true)->count(),
                    'no_contact' => $items->where('no_contact', true)->count(),
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function technicianRanking(array $periodLogs): array
    {
        return User::query()
            ->where('tenant_id', $this->currentUser()?->tenant_id)
            ->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_TECHNICIAN])
            ->where('status', 1)
            ->get(['id', 'name'])
            ->map(function (User $user) use ($periodLogs) {
                $budgetLogs = $periodLogs['budget']->filter(fn (OrderLog $log) => $log->order?->user_id === $user->id)->values();
                $paymentLogs = $periodLogs['payment']->filter(fn (OrderLog $log) => $log->order?->user_id === $user->id)->values();

                $budgetContacted = $budgetLogs->count();
                $budgetRecovered = $this->recoveredFromLogs($budgetLogs, 'budget');
                $paymentContacted = $paymentLogs->count();
                $paymentRecovered = $this->recoveredFromLogs($paymentLogs, 'payment');

                $contacted = $budgetContacted + $paymentContacted;
                $recovered = $budgetRecovered + $paymentRecovered;

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'contacted' => $contacted,
                    'recovered' => $recovered,
                    'rate' => $this->percentage($recovered, $contacted),
                ];
            })
            ->filter(fn (array $item) => $item['contacted'] > 0)
            ->sortBy([
                ['rate', 'asc'],
                ['contacted', 'desc'],
            ])
            ->take(5)
            ->values()
            ->all();
    }

    private function commercialDashboard(array $periodLogs): array
    {
        $budgetLogs = $periodLogs['budget'];
        $budgetOrders = $budgetLogs->pluck('order')->filter();
        $budgetContacted = $budgetLogs->count();
        $budgetApproved = $budgetOrders->filter(fn (Order $order) => ! in_array((int) $order->service_status, [
            OrderStatus::BUDGET_GENERATED,
            OrderStatus::BUDGET_REJECTED,
            OrderStatus::CANCELLED,
        ], true))->count();
        $budgetRejected = $budgetOrders->filter(fn (Order $order) => (int) $order->service_status === OrderStatus::BUDGET_REJECTED)->count();
        $budgetPending = max(0, $budgetContacted - $budgetApproved - $budgetRejected);

        $paymentLogs = $periodLogs['payment'];
        $paymentContacted = $paymentLogs->count();
        $paymentRecovered = $this->recoveredFromLogs($paymentLogs, 'payment');
        $paymentOpen = max(0, $paymentContacted - $paymentRecovered);

        $technicianPerformance = User::query()
            ->where('tenant_id', $this->currentUser()?->tenant_id)
            ->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_TECHNICIAN])
            ->where('status', 1)
            ->get(['id', 'name'])
            ->map(function (User $user) use ($periodLogs) {
                $budgetLogs = $periodLogs['budget']->filter(fn (OrderLog $log) => $log->order?->user_id === $user->id)->values();
                $budgetOrders = $budgetLogs->pluck('order')->filter();
                $budgetContacted = $budgetLogs->count();
                $budgetApproved = $budgetOrders->filter(fn (Order $order) => ! in_array((int) $order->service_status, [
                    OrderStatus::BUDGET_GENERATED,
                    OrderStatus::BUDGET_REJECTED,
                    OrderStatus::CANCELLED,
                ], true))->count();

                $paymentLogs = $periodLogs['payment']->filter(fn (OrderLog $log) => $log->order?->user_id === $user->id)->values();
                $paymentContacted = $paymentLogs->count();
                $paymentRecovered = $this->recoveredFromLogs($paymentLogs, 'payment');

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'budget_rate' => $this->percentage($budgetApproved, $budgetContacted),
                    'budget_contacted' => $budgetContacted,
                    'payment_rate' => $this->percentage($paymentRecovered, $paymentContacted),
                    'payment_contacted' => $paymentContacted,
                ];
            })
            ->filter(fn (array $item) => $item['budget_contacted'] > 0 || $item['payment_contacted'] > 0)
            ->sortByDesc(fn (array $item) => $item['budget_rate'] + $item['payment_rate'])
            ->take(5)
            ->values()
            ->all();

        return [
            'budget' => [
                'contacted' => $budgetContacted,
                'approved' => $budgetApproved,
                'rejected' => $budgetRejected,
                'pending' => $budgetPending,
                'rate' => $this->percentage($budgetApproved, $budgetContacted),
            ],
            'payment' => [
                'contacted' => $paymentContacted,
                'recovered' => $paymentRecovered,
                'open' => $paymentOpen,
                'rate' => $this->percentage($paymentRecovered, $paymentContacted),
            ],
            'technicians' => $technicianPerformance,
        ];
    }

    private function comparisonSummary(array $currentPeriodLogs, Carbon $from, Carbon $to): array
    {
        $days = max(1, $from->diffInDays($to) + 1);
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $currentRecovery = $this->recoverySummary($currentPeriodLogs);
        $previousRecovery = $this->recoverySummary($this->loadPeriodLogs($previousFrom, $previousTo));

        $build = function (float $current, float $previous): array {
            $delta = round($current - $previous, 1);

            return [
                'current' => $current,
                'previous' => $previous,
                'delta' => $delta,
                'direction' => $delta > 0 ? 'melhorou' : ($delta < 0 ? 'piorou' : 'estavel'),
            ];
        };

        return [
            'period' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
            ],
            'budget' => $build(
                (float) ($currentRecovery['budget']['rate'] ?? 0),
                (float) ($previousRecovery['budget']['rate'] ?? 0)
            ),
            'payment' => $build(
                (float) ($currentRecovery['payment']['rate'] ?? 0),
                (float) ($previousRecovery['payment']['rate'] ?? 0)
            ),
        ];
    }

    private function targetStatus(float $current, float $target): array
    {
        $delta = round($current - $target, 1);

        return [
            'target' => $target,
            'delta' => $delta,
            'status' => $delta >= 0 ? 'saudavel' : 'atencao',
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $thresholdDays = $this->communicationThresholdDays();
        $type = $request->get('type', 'all');
        [$metricsFrom, $metricsTo] = $this->metricsPeriod($request);
        $periodLogs = $this->loadPeriodLogs($metricsFrom, $metricsTo);

        $budgetQuery = (clone $this->baseQuery($request))
            ->withCount([
                'logs as budget_contact_count' => fn ($query) => $query->where('action', 'budget_follow_up_sent'),
            ])
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->where('updated_at', '<=', now()->subDays($thresholdDays))
            ->orderBy('budget_contact_count')
            ->orderBy('updated_at');
        $budgetQuery = $this->applyActiveFollowUpRules($budgetQuery, 'budget');
        $budgetQuery = $this->applyResponseFilter($budgetQuery, $request, 'budget');

        $paymentQuery = (clone $this->baseQuery($request))
            ->withCount([
                'logs as payment_contact_count' => fn ($query) => $query->where('action', 'payment_reminder_sent'),
            ])
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->where('delivery_date', '<=', now()->subDays($thresholdDays))
            ->whereRaw(
                '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
            )
            ->orderBy('payment_contact_count')
            ->orderByRaw('COALESCE(orders.delivery_date, orders.updated_at, orders.created_at)');
        $paymentQuery = $this->applyActiveFollowUpRules($paymentQuery, 'payment');
        if (! $this->financeEnabled()) {
            $paymentQuery->whereRaw('1 = 0');
        }
        $paymentQuery = $this->applyResponseFilter($paymentQuery, $request, 'payment');

        $budgetSummaryOrders = (clone $budgetQuery)
            ->get()
            ->map(fn (Order $order) => $this->appendFollowUpData($order));

        $paymentSummaryOrders = (clone $paymentQuery)
            ->get()
            ->map(fn (Order $order) => $this->appendFollowUpData($order));

        $feedbackSummaryOrders = (clone $this->baseQuery($request))
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('customer_feedback_submitted_at')
            ->where('customer_feedback_rating', '<=', 3)
            ->where(function ($query) {
                $query
                    ->whereNull('customer_feedback_recovery_status')
                    ->orWhere('customer_feedback_recovery_status', '!=', 'resolved');
            })
            ->get();

        $budgetOrders = $type === 'payment'
            ? null
            : $budgetQuery
                ->paginate(Pagination::perPage(), ['*'], 'budget_page')
                ->withQueryString()
                ->through(fn (Order $order) => $this->appendFollowUpData($order));

        $paymentOrders = $type === 'budget'
            ? null
            : $paymentQuery
                ->paginate(Pagination::perPage(), ['*'], 'payment_page')
                ->withQueryString()
                ->through(fn (Order $order) => $this->appendFollowUpData($order));

        $technicians = User::query()
            ->where('tenant_id', $this->currentUser()?->tenant_id)
            ->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_TECHNICIAN])
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('app/follow-ups/index', [
            'filters' => [
                'search' => $request->get('search'),
                'technician_id' => $request->get('technician_id'),
                'type' => $type,
                'response_status' => $request->get('response_status', 'all'),
                'from' => $metricsFrom->toDateString(),
                'to' => $metricsTo->toDateString(),
            ],
            'summary' => [
                'budget_follow_ups' => $budgetSummaryOrders->count(),
                'payment_follow_ups' => $paymentSummaryOrders->count(),
                'threshold_days' => $thresholdDays,
                'cooldown_days' => $thresholdDays,
                'automatic_follow_ups_enabled' => Other::automaticFollowUpsEnabled($this->currentUser()?->tenant_id),
                'recovery' => $this->recoverySummary($periodLogs),
                'trigger_performance' => $this->triggerPerformanceSummary($periodLogs),
                'commercial' => $this->commercialDashboard($periodLogs),
                'metrics_period' => [
                    'from' => $metricsFrom->toDateString(),
                    'to' => $metricsTo->toDateString(),
                ],
                'today_tasks' => $budgetSummaryOrders->count() + $paymentSummaryOrders->count() + $feedbackSummaryOrders->count(),
            ],
            'budgetOrders' => $budgetOrders,
            'paymentOrders' => $paymentOrders,
            'technicians' => $technicians,
            'technicianSummary' => $this->technicianSummary($budgetSummaryOrders, $paymentSummaryOrders),
            'technicianRanking' => $this->technicianRanking($periodLogs),
            'dailyAgenda' => $this->dailyAgenda($budgetSummaryOrders, $paymentSummaryOrders, $feedbackSummaryOrders),
            'trends' => [
                'budget' => $this->buildTrend($periodLogs['budget'], $metricsFrom, $metricsTo, 'budget'),
                'payment' => $this->buildTrend($periodLogs['payment'], $metricsFrom, $metricsTo, 'payment'),
            ],
        ]);
    }

    public function performance(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        [$metricsFrom, $metricsTo] = $this->metricsPeriod($request);
        $periodLogs = $this->loadPeriodLogs($metricsFrom, $metricsTo);
        $commercial = $this->commercialDashboard($periodLogs);

        return Inertia::render('app/follow-ups/performance', [
            'filters' => [
                'from' => $metricsFrom->toDateString(),
                'to' => $metricsTo->toDateString(),
            ],
            'summary' => [
                'recovery' => $this->recoverySummary($periodLogs),
                'trigger_performance' => $this->triggerPerformanceSummary($periodLogs),
                'commercial' => $commercial,
                'comparison' => $this->comparisonSummary($periodLogs, $metricsFrom, $metricsTo),
                'targets' => [
                    'budget' => $this->targetStatus(
                        (float) ($commercial['budget']['rate'] ?? 0),
                        Other::budgetConversionTarget($this->currentUser()?->tenant_id)
                    ),
                    'payment' => $this->targetStatus(
                        (float) ($commercial['payment']['rate'] ?? 0),
                        Other::paymentRecoveryTarget($this->currentUser()?->tenant_id)
                    ),
                ],
                'metrics_period' => [
                    'from' => $metricsFrom->toDateString(),
                    'to' => $metricsTo->toDateString(),
                ],
            ],
            'technicianRanking' => $this->technicianRanking($periodLogs),
            'trends' => [
                'budget' => $this->buildTrend($periodLogs['budget'], $metricsFrom, $metricsTo, 'budget'),
                'payment' => $this->buildTrend($periodLogs['payment'], $metricsFrom, $metricsTo, 'payment'),
            ],
        ]);
    }

    public function tasks(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $user = $this->currentUser();

        $thresholdDays = $this->communicationThresholdDays();

        $budgetOrders = (clone $this->baseQuery($request))
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->where('updated_at', '<=', now()->subDays($thresholdDays));
        $budgetOrders = $this->applyActiveFollowUpRules($budgetOrders, 'budget')
            ->get()
            ->map(fn (Order $order) => $this->appendFollowUpData($order));

        $paymentOrders = (clone $this->baseQuery($request))
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->where('delivery_date', '<=', now()->subDays($thresholdDays))
            ->whereRaw(
                '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
            );
        $paymentOrders = $this->applyActiveFollowUpRules($paymentOrders, 'payment')
            ->get()
            ->map(fn (Order $order) => $this->appendFollowUpData($order));
        if (! $this->financeEnabled()) {
            $paymentOrders = collect();
        }

        $feedbackOrders = (clone $this->baseQuery($request))
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('customer_feedback_submitted_at')
            ->where('customer_feedback_rating', '<=', 3)
            ->where(function ($query) {
                $query
                    ->whereNull('customer_feedback_recovery_status')
                    ->orWhere('customer_feedback_recovery_status', '!=', 'resolved');
            })
            ->get();

        $technicians = User::query()
            ->where('tenant_id', $this->currentUser()?->tenant_id)
            ->whereIn('roles', [User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN])
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($user && $user->status && ! $technicians->contains('id', $user->id)) {
            $technicians = $technicians
                ->push($user->only(['id', 'name']))
                ->sortBy('name')
                ->values();
        }

        $dailyAgenda = collect($this->dailyAgenda($budgetOrders, $paymentOrders, $feedbackOrders, null));
        $type = (string) $request->get('type', 'all');
        $priority = (string) $request->get('priority', 'all');
        $sort = (string) $request->get('sort', 'priority');
        $assignedTo = (string) $request->get(
            'assigned_to',
            $user && ! $user->isRoot() && ! $user->isAdministrator()
                ? (string) $user->id
                : 'all'
        );

        if ($type !== 'all') {
            $dailyAgenda = $dailyAgenda->where('scope', $type);
        }

        if ($assignedTo === 'unassigned') {
            $dailyAgenda = $dailyAgenda->filter(fn (array $item) => empty($item['assigned_to_id']));
        } elseif ($assignedTo !== 'all') {
            $dailyAgenda = $dailyAgenda->where('assigned_to_id', (int) $assignedTo);
        }

        if ($priority !== 'all') {
            $dailyAgenda = $dailyAgenda->where('priority', $priority);
        }

        $dailyAgenda = match ($sort) {
            'days' => $dailyAgenda->sortByDesc('days_pending'),
            default => $dailyAgenda->sortByDesc(fn (array $item) => match ($item['priority'] ?? 'normal') {
                'critica' => 3,
                'alta' => 2,
                default => 1,
            } * 100 + ($item['days_pending'] ?? 0)),
        };

        $dailyAgenda = $dailyAgenda->values();

        return Inertia::render('app/follow-ups/tasks', [
            'filters' => [
                'type' => $type,
                'priority' => $priority,
                'sort' => $sort,
                'assigned_to' => $assignedTo,
            ],
            'summary' => [
                'today_tasks' => $dailyAgenda->count(),
                'budget_tasks' => $dailyAgenda->where('scope', 'budget')->count(),
                'payment_tasks' => $dailyAgenda->where('scope', 'payment')->count(),
                'feedback_tasks' => $dailyAgenda->where('scope', 'feedback')->count(),
                'unassigned_tasks' => $dailyAgenda->filter(fn (array $item) => empty($item['assigned_to_id']))->count(),
                'critical_tasks' => $dailyAgenda->where('priority', 'critica')->count(),
                'my_tasks' => $user ? $dailyAgenda->where('assigned_to_id', $user->id)->count() : 0,
                'by_assignee' => $dailyAgenda
                    ->groupBy(fn (array $item) => $item['assigned_to_id'] ?: 'unassigned')
                    ->map(function (Collection $items, $key) {
                        $first = $items->first();

                        return [
                            'key' => $key,
                            'name' => $first['assigned_to'] ?? 'Sem responsável pelo acompanhamento',
                            'total' => $items->count(),
                            'critical' => $items->where('priority', 'critica')->count(),
                            'high' => $items->where('priority', 'alta')->count(),
                        ];
                    })
                    ->sortByDesc(fn (array $item) => ($item['critical'] * 100) + ($item['high'] * 10) + $item['total'])
                    ->values()
                    ->all(),
            ],
            'dailyAgenda' => $dailyAgenda->all(),
            'technicians' => $technicians,
        ]);
    }

    public function pause(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment,feedback',
            'reason' => 'required|string|max:500',
        ]);

        if ($validated['scope'] === 'feedback') {
            $this->followUpTaskService->pause($order, 'feedback', trim((string) $validated['reason']), $this->currentUser()?->id);

            return back()->with('success', 'Tarefa marcada como concluída.');
        }

        $this->followUpTaskService->pause($order, $validated['scope'], trim((string) $validated['reason']), $this->currentUser()?->id);

        return back()->with('success', 'Automação pausada para esta ordem.');
    }

    public function resume(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment',
        ]);

        $this->followUpTaskService->resume($order, $validated['scope']);

        return back()->with('success', 'Automação reativada para esta ordem.');
    }

    public function respond(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment',
            'status' => 'required|in:responded,no_interest,waiting_piece,promised_payment',
        ]);

        $label = $this->responseLabel($validated['status']);

        $this->followUpTaskService->respond($order, $validated['scope'], $validated['status'], $label ?? $validated['status'], $this->currentUser()?->id);

        return back()->with('success', 'Retorno do cliente registrado com sucesso.');
    }

    public function completeTask(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment',
            'reason' => 'required|string|max:500',
        ]);

        $columns = $this->scopeColumns($validated['scope']);

        $this->followUpTaskService->completeTask($order, $validated['scope']);

        $this->logOrderAction($order, $columns['log_task_completed'], [
            'scope' => $validated['scope'],
            'reason' => trim((string) $validated['reason']),
            'assigned_to' => match ($validated['scope']) {
                'budget' => $order->budgetFollowUpAssignee?->name,
                'payment' => $order->paymentFollowUpAssignee?->name,
            },
        ]);

        return back()->with('success', 'Tarefa marcada como concluída.');
    }

    public function snoozeTask(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment',
            'days' => 'required|integer|min:1|max:30',
        ]);

        $this->followUpTaskService->snoozeTask($order, $validated['scope'], (int) $validated['days']);

        return back()->with('success', 'Tarefa adiada com sucesso.');
    }

    public function assignTask(Request $request, Order $order)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'scope' => 'required|in:budget,payment,feedback',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $assignee = null;

        if (! empty($validated['user_id'])) {
            $assignee = User::query()
                ->where('tenant_id', $this->currentUser()?->tenant_id)
                ->where('id', $validated['user_id'])
                ->where('status', 1)
                ->firstOrFail();
        }

        if ($validated['scope'] === 'feedback') {
            $this->followUpTaskService->assignTask($order, 'feedback', $assignee);

            return back()->with('success', 'Responsável da tarefa atualizado.');
        }

        $this->followUpTaskService->assignTask($order, $validated['scope'], $assignee);

        return back()->with('success', 'Responsável da tarefa atualizado.');
    }

    public function assignSelectedTasks(Request $request)
    {
        $this->authorizeFollowUpManagement();
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'tasks' => 'required|array|min:1',
            'tasks.*.order_id' => 'required|integer|exists:orders,id',
            'tasks.*.scope' => 'required|in:budget,payment,feedback',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $assignee = null;

        if (! empty($validated['user_id'])) {
            $assignee = User::query()
                ->where('tenant_id', $this->currentUser()?->tenant_id)
                ->where('id', $validated['user_id'])
                ->where('status', 1)
                ->firstOrFail();
        }

        $updated = 0;

        foreach ($validated['tasks'] as $task) {
            $order = Order::query()->find($task['order_id']);

            if (! $order) {
                continue;
            }

            if ($this->currentUser()?->cannot('update', $order)) {
                continue;
            }

            if ($task['scope'] === 'feedback') {
                $this->followUpTaskService->assignTask($order, 'feedback', $assignee);

                $updated++;

                continue;
            }

            $this->followUpTaskService->assignTask($order, $task['scope'], $assignee);

            $updated++;
        }

        return back()->with('success', "{$updated} tarefa(s) atualizada(s) com sucesso.");
    }
}
