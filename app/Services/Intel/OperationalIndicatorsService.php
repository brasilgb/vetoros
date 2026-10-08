<?php

namespace App\Services\Intel;

use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderEvent;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\User;
use App\Services\OrderMarginService;
use App\Support\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores operacionais e comerciais (VETOR-INTEL-04). Regras documentadas em
 * docs/architecture/vetor-intel-04-indicadores.md.
 *
 * Toda consulta filtra o tenant explicitamente. Registro sem fonte confiável não entra
 * no cálculo e é contado como desconhecido — nenhum valor é presumido.
 */
class OperationalIndicatorsService
{
    /** Status fora do fluxo ativo. */
    public const INACTIVE_STATUSES = [OrderStatus::CANCELLED, OrderStatus::DELIVERED, OrderStatus::SERVICE_NOT_EXECUTED];

    public function __construct(private readonly OrderMarginService $margins) {}

    /**
     * @return array<string, mixed>
     */
    public function all(int $tenantId, Carbon $from, Carbon $to, int $stalledDays = 7, int $expiringDays = 3): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $indicators = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stalled_orders' => $this->stalledOrders($tenantId, $stalledDays),
            'overdue_orders' => $this->overdueOrders($tenantId),
            'budgets_awaiting' => $this->budgetsAwaiting($tenantId),
            'budgets_expiring' => $this->budgetsExpiring($tenantId, $expiringDays),
            'budget_conversion' => $this->budgetConversion($tenantId, $from, $to),
            'technician_productivity' => $this->technicianProductivity($tenantId, $from, $to),
            'deadline_compliance' => $this->deadlineCompliance($tenantId, $from, $to),
            'profitability' => $this->profitability($tenantId, $from, $to),
        ];

        $indicators['data_quality'] = [
            'stalled_reference_unknown' => $indicators['stalled_orders']['reference_unknown'],
            'budgets_age_unknown' => $indicators['budgets_awaiting']['age_unknown'],
            'productivity_unattributed_events' => $indicators['technician_productivity']['unattributed']['completed']
                + $indicators['technician_productivity']['unattributed']['delivered'],
            'deadline_original_unknown' => $indicators['deadline_compliance']['original_unknown'],
            'profitability_incomplete' => $indicators['profitability']['incomplete'],
        ];

        return $indicators;
    }

    /**
     * @return array{threshold_days: int, total: int, reference_unknown: int, orders: list<array<string, mixed>>}
     */
    public function stalledOrders(int $tenantId, int $days = 7): array
    {
        $orders = $this->activeOrders($tenantId)->get(['id', 'order_number', 'service_status']);
        $ids = $orders->pluck('id');

        $lastEvent = DB::table('order_events')
            ->where('tenant_id', $tenantId)
            ->whereIn('order_id', $ids)
            ->whereIn('event_type', OrderEvent::STATUS_TIMELINE_TYPES)
            ->groupBy('order_id')
            ->pluck(DB::raw('MAX(occurred_at)'), 'order_id');
        $lastHistory = DB::table('order_status_history')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id')
            ->pluck(DB::raw('MAX(created_at)'), 'order_id');

        $limit = now()->subDays($days);
        $stalled = [];
        $unknown = 0;

        foreach ($orders as $order) {
            $reference = $lastEvent[$order->id] ?? $lastHistory[$order->id] ?? null;

            if ($reference === null) {
                $unknown++;

                continue;
            }

            $reference = Carbon::parse($reference);

            if ($reference->lessThan($limit)) {
                $stalled[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => (int) $order->service_status,
                    'days' => (int) $reference->diffInDays(now()),
                    'reference' => isset($lastEvent[$order->id]) ? 'order_events' : 'order_status_history',
                ];
            }
        }

        usort($stalled, fn (array $a, array $b) => $b['days'] <=> $a['days']);

        return ['threshold_days' => $days, 'total' => count($stalled), 'reference_unknown' => $unknown, 'orders' => $stalled];
    }

    /**
     * @return array{total: int, renegotiated: int}
     */
    public function overdueOrders(int $tenantId): array
    {
        $overdue = $this->activeOrders($tenantId)
            ->whereNotNull('delivery_forecast')
            ->whereDate('delivery_forecast', '<', now()->toDateString())
            ->get(['delivery_forecast', 'original_delivery_forecast']);

        return [
            'total' => $overdue->count(),
            'renegotiated' => $overdue->filter(fn ($order) => $order->original_delivery_forecast !== null
                && $order->original_delivery_forecast !== $order->delivery_forecast)->count(),
        ];
    }

    /**
     * @return array{count: int, amount: float, average_age_days: float|null, age_unknown: int}
     */
    public function budgetsAwaiting(int $tenantId): array
    {
        $budgets = $this->currentBudgets($tenantId)
            ->filter(fn (OrderBudget $budget) => $budget->status === OrderBudget::STATUS_SENT && ! $budget->isExpired());
        $dated = $budgets->filter(fn (OrderBudget $budget) => $budget->sent_at !== null);

        return [
            'count' => $budgets->count(),
            'amount' => round((float) $budgets->sum('quoted_amount'), 2),
            'average_age_days' => $dated->isEmpty() ? null : round($dated->avg(fn (OrderBudget $b) => $b->sent_at->diffInHours(now()) / 24), 1),
            'age_unknown' => $budgets->count() - $dated->count(),
        ];
    }

    /**
     * @return array{within_days: int, count: int, amount: float, budgets: list<array<string, mixed>>}
     */
    public function budgetsExpiring(int $tenantId, int $days = 3): array
    {
        $today = now()->toDateString();
        $limit = now()->addDays($days)->toDateString();
        $budgets = $this->currentBudgets($tenantId)
            ->filter(fn (OrderBudget $budget) => $budget->status === OrderBudget::STATUS_SENT
                && $budget->valid_until !== null
                && $budget->valid_until->toDateString() >= $today
                && $budget->valid_until->toDateString() <= $limit);

        return [
            'within_days' => $days,
            'count' => $budgets->count(),
            'amount' => round((float) $budgets->sum('quoted_amount'), 2),
            'budgets' => $budgets->map(fn (OrderBudget $budget) => [
                'order_id' => $budget->order_id,
                'version' => (int) $budget->version,
                'valid_until' => $budget->valid_until->toDateString(),
                'quoted_amount' => round((float) $budget->quoted_amount, 2),
            ])->values()->all(),
        ];
    }

    /**
     * Conversão por ciclo de orçamento da OS + tempo de aprovação.
     *
     * @return array<string, mixed>
     */
    public function budgetConversion(int $tenantId, Carbon $from, Carbon $to): array
    {
        $versions = OrderBudget::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_legacy', false)
            ->orderBy('version')
            ->get()
            ->groupBy('order_id')
            ->filter(function (Collection $cycle) use ($from, $to) {
                $firstSent = $cycle->whereNotNull('sent_at')->min('sent_at');

                return $firstSent !== null && Carbon::parse($firstSent)->between($from, $to);
            });

        $outcomes = ['approved' => 0, 'rejected' => 0, 'expired' => 0, 'pending' => 0];
        $approvalHours = [];
        $versionsPerCycle = [];

        foreach ($versions as $cycle) {
            $firstSent = Carbon::parse($cycle->whereNotNull('sent_at')->min('sent_at'));
            $approved = $cycle->firstWhere('status', OrderBudget::STATUS_APPROVED)
                ?? $cycle->filter(fn (OrderBudget $b) => $b->approved_at !== null)->sortByDesc('approved_at')->first();
            $current = $cycle->sortByDesc('version')->first();
            $lastResponse = $cycle->filter(fn (OrderBudget $b) => $b->responded_at !== null)->sortByDesc('responded_at')->first();
            $versionsPerCycle[] = $cycle->count();

            if ($approved) {
                $outcomes['approved']++;
                $approvalHours[] = $firstSent->diffInMinutes($approved->approved_at) / 60;
            } elseif ($lastResponse && $lastResponse->status === OrderBudget::STATUS_REJECTED) {
                $outcomes['rejected']++;
            } elseif ($current->isExpired()) {
                $outcomes['expired']++;
            } else {
                $outcomes['pending']++;
            }
        }

        $decided = $outcomes['approved'] + $outcomes['rejected'] + $outcomes['expired'];
        sort($approvalHours);

        return [
            'cycles' => $versions->count(),
            ...$outcomes,
            'conversion_rate' => $decided > 0 ? round($outcomes['approved'] / $decided * 100, 1) : null,
            'approval_time_hours' => [
                'average' => $approvalHours === [] ? null : round(array_sum($approvalHours) / count($approvalHours), 1),
                'median' => $approvalHours === [] ? null : round($this->median($approvalHours), 1),
            ],
            'average_versions_per_cycle' => $versionsPerCycle === [] ? null : round(array_sum($versionsPerCycle) / count($versionsPerCycle), 2),
            'legacy_excluded' => OrderBudget::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_legacy', true)->count(),
        ];
    }

    /**
     * @return array{technicians: list<array<string, mixed>>, unattributed: array{completed: int, delivered: int}}
     */
    public function technicianProductivity(int $tenantId, Carbon $from, Carbon $to): array
    {
        $events = OrderEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', [OrderEvent::TYPE_STATUS_CHANGED, OrderEvent::TYPE_ORDER_REOPENED])
            ->whereIn('to_status', [OrderStatus::SERVICE_COMPLETED, OrderStatus::DELIVERED])
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['order_id', 'to_status', 'occurred_at']);

        $stats = [];
        $unattributed = ['completed' => 0, 'delivered' => 0];
        $completedByTechnician = [];

        foreach ($events as $event) {
            $key = (int) $event->to_status === OrderStatus::SERVICE_COMPLETED ? 'completed' : 'delivered';
            $technicianId = OrderTechnicianAssignment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $event->order_id)
                ->activeAt($event->occurred_at)
                ->value('technician_id');

            if (! $technicianId) {
                $unattributed[$key]++;

                continue;
            }

            $stats[$technicianId] ??= ['completed' => 0, 'delivered' => 0, 'warranty_returns' => 0];
            $stats[$technicianId][$key]++;

            if ($key === 'completed') {
                $completedByTechnician[$event->order_id] = $technicianId;
            }
        }

        // Retornos em garantia cuja OS de origem foi concluída pelo técnico no período.
        if ($completedByTechnician !== []) {
            Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_warranty_return', true)
                ->whereIn('warranty_source_order_id', array_keys($completedByTechnician))
                ->pluck('warranty_source_order_id')
                ->each(function ($sourceId) use (&$stats, $completedByTechnician) {
                    $stats[$completedByTechnician[$sourceId]]['warranty_returns']++;
                });
        }

        $names = User::withoutGlobalScopes()->whereIn('id', array_keys($stats))->where('tenant_id', $tenantId)->pluck('name', 'id');

        return [
            'technicians' => collect($stats)->map(fn (array $row, $id) => ['technician_id' => (int) $id, 'name' => $names[$id] ?? null, ...$row])
                ->sortBy('name')->values()->all(),
            'unattributed' => $unattributed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deadlineCompliance(int $tenantId, Carbon $from, Carbon $to): array
    {
        $deliveries = $this->deliveries($tenantId, $from, $to);
        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', $deliveries->keys())
            ->get(['id', 'original_delivery_forecast', 'delivery_date'])
            ->keyBy('id');
        $renegotiated = DB::table('order_events')
            ->where('tenant_id', $tenantId)
            ->whereIn('order_id', $deliveries->keys())
            ->where('event_type', OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED)
            ->distinct()
            ->pluck('order_id')
            ->flip();

        $onTime = 0;
        $late = 0;
        $lateDays = [];
        $unknown = 0;

        foreach ($deliveries as $orderId => $deliveredAt) {
            $original = $orders[$orderId]->original_delivery_forecast ?? null;

            if ($original === null) {
                $unknown++;

                continue;
            }

            // Data da entrega gravada na OS (inclui correções); sem ela, o instante do evento.
            $delivered = $orders[$orderId]->delivery_date ?? $deliveredAt;
            $delay = Carbon::parse($original)->startOfDay()->diffInDays(Carbon::parse($delivered)->startOfDay(), false);

            if ($delay <= 0) {
                $onTime++;
            } else {
                $late++;
                $lateDays[] = $delay;
            }
        }

        $known = $onTime + $late;

        return [
            'delivered' => $deliveries->count(),
            'on_time' => $onTime,
            'late' => $late,
            'on_time_rate' => $known > 0 ? round($onTime / $known * 100, 1) : null,
            'average_delay_days' => $lateDays === [] ? null : round(array_sum($lateDays) / count($lateDays), 1),
            'renegotiated' => $deliveries->keys()->filter(fn ($id) => isset($renegotiated[$id]))->count(),
            'original_unknown' => $unknown,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profitability(int $tenantId, Carbon $from, Carbon $to): array
    {
        $orderIds = $this->deliveries($tenantId, $from, $to)->keys();
        $revenue = 0.0;
        $margin = 0.0;
        $complete = 0;
        $missing = [];

        Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $orderIds)->get()
            ->each(function (Order $order) use (&$revenue, &$margin, &$complete, &$missing) {
                $breakdown = $this->margins->breakdown($order);

                if ($breakdown['complete']) {
                    $complete++;
                    $revenue += $breakdown['revenue']['total'];
                    $margin += $breakdown['margin'];

                    return;
                }

                foreach ($breakdown['costs'] as $component => $cost) {
                    if ($cost['status'] !== OrderMarginService::KNOWN) {
                        $missing[$component][$cost['status']] = ($missing[$component][$cost['status']] ?? 0) + 1;
                    }
                }
            });

        return [
            'delivered' => $orderIds->count(),
            'complete' => $complete,
            'incomplete' => $orderIds->count() - $complete,
            'revenue_complete' => round($revenue, 2),
            'margin_complete' => round($margin, 2),
            'margin_rate_complete' => $revenue > 0 ? round($margin / $revenue * 100, 1) : null,
            'incomplete_by_component' => $missing,
        ];
    }

    private function activeOrders(int $tenantId)
    {
        return Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('service_status', self::INACTIVE_STATUSES);
    }

    /**
     * Versão corrente (maior versão) de cada OS do tenant.
     *
     * @return Collection<int, OrderBudget>
     */
    private function currentBudgets(int $tenantId): Collection
    {
        return OrderBudget::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', OrderBudget::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->selectRaw('MAX(id)')
                ->groupBy('order_id'))
            ->get();
    }

    /**
     * Última entrega (evento → Entregue) de cada OS no período: order_id => delivered_at.
     *
     * @return Collection<int, string>
     */
    private function deliveries(int $tenantId, Carbon $from, Carbon $to): Collection
    {
        return DB::table('order_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', OrderEvent::TYPE_STATUS_CHANGED)
            ->where('to_status', OrderStatus::DELIVERED)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('order_id')
            ->pluck(DB::raw('MAX(occurred_at)'), 'order_id');
    }

    /**
     * @param  list<float>  $sorted
     */
    private function median(array $sorted): float
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
