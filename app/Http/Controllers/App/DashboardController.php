<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Expense;
use App\Models\App\Message;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\PurchaseOrder;
use App\Models\App\Sale;
use App\Models\App\Schedule;
use App\Models\User;
use App\Support\OrderStatus;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    private function scopeOrdersQuery($query)
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isTechnician() && ! $user->canViewAllOrders()) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            });
        }

        return $query;
    }

    private function scopeSchedulesQuery($query)
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isTechnician()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    private function warrantyReturnIndicator(int $totalOrders, int $warrantyReturns): array
    {
        $threshold = Other::warrantyReturnAlertThreshold();
        $rate = $totalOrders > 0 ? round(($warrantyReturns / $totalOrders) * 100, 1) : 0.0;

        return [
            'warranty_return_threshold' => $threshold,
            'warranty_return_rate' => $rate,
            'warranty_return_alert' => $rate > $threshold,
        ];
    }

    public function index()
    {
        $feedbackDelay = Other::customerFeedbackRequestDelayDays(auth()->user()?->tenant_id);
        $feedbackThreshold = Carbon::now()->subDays($feedbackDelay)->endOfDay();
        $feedbackExpirationThreshold = Carbon::now()->subDays($feedbackDelay + 7);
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        $pendingOrdersQuery = $this->scopeOrdersQuery(Order::query())
            ->whereNotNull('delivery_forecast')
            ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED]);

        $ordersQuery = $this->scopeOrdersQuery(Order::query());
        $schedulesQuery = $this->scopeSchedulesQuery(Schedule::query());
        $overdueSchedulesQuery = (clone $schedulesQuery)
            ->whereIn('status', [1, 2])
            ->where('schedules', '<', $today->copy()->startOfDay());

        $acount = [
            'numuser' => User::count(),
            'numcust' => Customer::count(),
            'numorde' => (clone $ordersQuery)->count(),
            'numorde_warranty_return' => (clone $ordersQuery)->where('is_warranty_return', true)->count(),
            'numorde_due_today' => (clone $pendingOrdersQuery)->whereDate('delivery_forecast', $today)->count(),
            'numorde_due_tomorrow' => (clone $pendingOrdersQuery)->whereDate('delivery_forecast', $tomorrow)->count(),
            'numorde_overdue' => (clone $pendingOrdersQuery)->whereDate('delivery_forecast', '<', $today)->count(),
            'numorde_unassigned' => (clone $ordersQuery)
                ->whereNull('user_id')
                ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED])
                ->count(),
            'numorde_awaiting_pickup' => (clone $ordersQuery)->where('service_status', OrderStatus::CUSTOMER_NOTIFIED)->count(),
            'numorde_awaiting_approval' => (clone $ordersQuery)->where('service_status', OrderStatus::BUDGET_GENERATED)->count(),
            'numparts_low_stock' => Part::query()
                ->where('is_sellable', true)
                ->whereColumn('quantity', '<=', 'minimum_stock_level')
                ->count(),
            'numshed' => (clone $schedulesQuery)->count(),
            'numshed_open' => (clone $schedulesQuery)->where('status', 1)->count(),
            'numshed_in_progress' => (clone $schedulesQuery)->where('status', 2)->count(),
            'numshed_overdue' => (clone $overdueSchedulesQuery)->count(),
            'nummess' => Message::count(),
            'numparts' => Part::where('type', 'part')->count(),
            'numproducts' => Part::where('type', 'product')->count(),
        ];
        $orders = [
            'agendados' => (clone $schedulesQuery)->where('status', 1)->orderBy('schedules')->get(['id', 'schedules_number']),
            'em_atendimento' => (clone $schedulesQuery)->where('status', 2)->orderBy('schedules')->get(['id', 'schedules_number']),
            'atrasados' => (clone $overdueSchedulesQuery)->orderBy('schedules')->get(['id', 'schedules_number']),
            'gerados' => (clone $ordersQuery)->where('service_status', OrderStatus::BUDGET_GENERATED)->get(['id', 'order_number']),
            'aprovados' => (clone $ordersQuery)->where('service_status', OrderStatus::BUDGET_APPROVED)->get(['id', 'order_number']),
            'concluidosca' => (clone $ordersQuery)->where('service_status', OrderStatus::CUSTOMER_NOTIFIED)->get(['id', 'order_number']),
            'concluidoscn' => (clone $ordersQuery)->whereIn('service_status', [
                OrderStatus::SERVICE_COMPLETED,
            ])->get(['id', 'order_number']),
            'garantia' => (clone $ordersQuery)->where('is_warranty_return', true)->get(['id', 'order_number']),
            'feedback' => (clone $ordersQuery)->where('service_status', OrderStatus::DELIVERED)
                ->whereNotNull('delivery_date')
                ->where('delivery_date', '<=', $feedbackThreshold)
                ->where('delivery_date', '>', $feedbackExpirationThreshold)
                ->whereNull('customer_feedback_submitted_at')
                ->whereNull('customer_feedback_request_expired_at')
                ->get(['id', 'order_number']),
        ];
        $listSchedules = $this->scopeSchedulesQuery(Schedule::with('user', 'customer'))->get();
        $parts = Part::where('is_sellable', true)->get();
        $others = Other::query()
            ->where('tenant_id', auth()->user()?->tenant_id)
            ->first();
        $openCashSession = CashSession::query()
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        return Inertia::render('app/dashboard/index', [
            'listSchedules' => $listSchedules,
            'reloadKey' => now()->timestamp,
            'orders' => $orders,
            'feedbackDelay' => $feedbackDelay,
            'acount' => $acount,
            'parts' => $parts,
            'others' => $others,
            'cashier' => [
                'isOpen' => (bool) $openCashSession,
                'openedAt' => $openCashSession?->opened_at?->toIso8601String(),
            ],
            'warrantyIndicator' => $this->warrantyReturnIndicator(
                (int) $acount['numorde'],
                (int) $acount['numorde_warranty_return']
            ),
        ]);
    }

    private function getRange($timerange)
    {
        $from = request()->query('from');
        $to = request()->query('to');

        if ($from && $to) {
            try {
                $start = Carbon::parse($from)->startOfDay();
                $end = Carbon::parse($to)->endOfDay();

                if ($start->gt($end)) {
                    [$start, $end] = [$end, $start];
                }

                return [$start, $end];
            } catch (\Exception $e) {
                // fallback para range baseado em $timerange
            }
        }

        $timerange = intval($timerange) > 0 ? intval($timerange) : 7;
        $start = Carbon::now()->subDays($timerange - 1)->startOfDay();
        $end = Carbon::now()->endOfDay();

        return [$start, $end];
    }

    private function getPreviousRange(Carbon $startDate, Carbon $endDate): array
    {
        $rangeDays = max(1, $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1);
        $previousEnd = $startDate->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($rangeDays - 1)->startOfDay();

        return [$previousStart, $previousEnd];
    }

    private function comparison(float|int $current, float|int $previous): array
    {
        $current = (float) $current;
        $previous = (float) $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $current - $previous,
            'percent' => abs($previous) > 0.00001 ? round((($current - $previous) / abs($previous)) * 100, 1) : null,
        ];
    }

    private function normalizePaymentMethod(?string $method): string
    {
        $normalized = str($method ?? '')
            ->lower()
            ->ascii()
            ->replace([' ', '-', '.'], '_')
            ->toString();

        return match ($normalized) {
            'pix' => 'pix',
            'cartao', 'cartao_credito', 'cartao_de_credito', 'credito', 'credit_card', 'card' => 'cartao',
            'dinheiro', 'cash' => 'dinheiro',
            'transferencia', 'transferencia_bancaria', 'bank_transfer', 'ted', 'doc' => 'transferencia',
            'boleto', 'bank_slip' => 'boleto',
            default => 'outros',
        };
    }

    public function chartEquipments($timerange)
    {
        [$start, $end] = $this->getRange($timerange);

        $equipments = Equipment::where('chart', 1)
            ->get();

        $selects = [
            DB::raw('DATE(created_at) as date'),
        ];

        foreach ($equipments as $equipment) {
            $selects[] = DB::raw(
                "SUM(CASE WHEN equipment_id = {$equipment->id} THEN 1 ELSE 0 END) as eq_{$equipment->id}"
            );
        }

        $orders = $this->scopeOrdersQuery(Order::query())->select($selects)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {

            $key = $date->format('Y-m-d');

            $row = [
                'date' => $date->format('Y-m-d'),
            ];

            foreach ($equipments as $equipment) {

                $field = "eq_{$equipment->id}";

                $row[$field] = $orders[$key]->$field ?? 0;
            }

            $result[] = $row;
        }

        return response()->json([
            'lines' => $equipments->map(fn ($e) => [
                'key' => "eq_{$e->id}",
                'label' => $e->equipment,
            ]),
            'data' => $result,
        ]);
    }

    public function fluxsOrders($timerange)
    {
        [$start, $end] = $this->getRange($timerange);

        $orders = $this->scopeOrdersQuery(Order::query())->selectRaw('
            DATE(created_at) as date,
            COUNT(*) as entradas,
            SUM(CASE WHEN service_status IN (7,9) THEN 1 ELSE 0 END) as concluidos,
            SUM(CASE WHEN service_status = 10 THEN 1 ELSE 0 END) as entregues
        ')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {

            $key = $date->format('Y-m-d');
            $data = $orders->get($key);

            $result[] = [
                'period' => $date->format('Y-m-d'),
                'entradas' => $data->entradas ?? 0,
                'concluidos' => $data->concluidos ?? 0,
                'entregues' => $data->entregues ?? 0,
            ];
        }

        return response()->json($result);
    }

    public function budgetsStatusChart($timerange)
    {
        [$start, $end] = $this->getRange($timerange);

        $generated = $this->scopeOrdersQuery(Order::query())
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $approved = $this->scopeOrdersQuery(Order::query())
            ->where('service_status', OrderStatus::BUDGET_APPROVED)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $approvalRate = $generated > 0 ? round(min(100, ($approved / $generated) * 100), 1) : 0;

        return response()->json([
            'generated' => $generated,
            'approved' => $approved,
            'total' => $generated,
            'approval_rate' => $approvalRate,
        ]);
    }

    public function metricsSystem($timerange)
    {
        [$startDate, $endDate] = $this->getRange($timerange);

        $ordersCount = $this->scopeOrdersQuery(Order::query())->whereBetween('created_at', [$startDate, $endDate])->count();
        $warrantyReturns = $this->scopeOrdersQuery(Order::query())->where('is_warranty_return', true)->whereBetween('created_at', [$startDate, $endDate])->count();
        $deliveredOrders = $this->scopeOrdersQuery(Order::query())
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereBetween('created_at', [$startDate, $endDate]);
        $feedbackResponsesQuery = $this->scopeOrdersQuery(Order::query())
            ->whereNotNull('customer_feedback_submitted_at')
            ->whereBetween('customer_feedback_submitted_at', [$startDate, $endDate]);
        $feedbackResponses = (clone $feedbackResponsesQuery)->count();
        $feedbackAverageRating = round(
            (float) ((clone $feedbackResponsesQuery)->avg('customer_feedback_rating') ?? 0),
            1
        );
        $lowFeedbackCount = (clone $feedbackResponsesQuery)
            ->where('customer_feedback_rating', '<=', 3)
            ->count();
        $feedbackResponseRate = (clone $deliveredOrders)->count() > 0
            ? min(100, round(($feedbackResponses / (clone $deliveredOrders)->count()) * 100, 1))
            : 0.0;
        $communicationThreshold = now()->subDays(2);

        $budgetFollowUps = $this->scopeOrdersQuery(Order::query())
            ->where('service_status', OrderStatus::BUDGET_GENERATED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereBudgetPendingBefore($communicationThreshold)
            ->count();

        $pendingPaymentFollowUps = Other::financeEnabled(auth()->user()?->tenant_id)
            ? $this->scopeOrdersQuery(Order::query())
                ->where('service_status', OrderStatus::DELIVERED)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('delivery_date')
                ->where('delivery_date', '<=', $communicationThreshold)
                ->whereRaw(
                    '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
                )
                ->count()
            : 0;

        return response()->json([

            'customers' => Customer::whereBetween('created_at', [$startDate, $endDate])->count(),

            'orders' => $ordersCount,

            'schedules' => Schedule::whereBetween('created_at', [$startDate, $endDate])->count(),

            'messages' => Message::whereBetween('created_at', [$startDate, $endDate])->count(),

            'parts' => Part::where('type', 'part')->whereBetween('created_at', [$startDate, $endDate])->count(),

            'products' => Part::where('type', 'product')->whereBetween('created_at', [$startDate, $endDate])->count(),

            'warranty_returns' => $warrantyReturns,
            'feedback_responses' => $feedbackResponses,
            'feedback_average_rating' => $feedbackAverageRating,
            'feedback_response_rate' => $feedbackResponseRate,
            'low_feedbacks' => $lowFeedbackCount,
            'feedback_alert' => $lowFeedbackCount > 0,
            'budget_follow_ups' => $budgetFollowUps,
            'pending_payment_follow_ups' => $pendingPaymentFollowUps,
            ...$this->warrantyReturnIndicator($ordersCount, $warrantyReturns),
        ]);
    }

    public function kpisFinancialOrder($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);
        [$previousStartDate, $previousEndDate] = $this->getPreviousRange($startDate, $endDate);
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth()->startOfDay();
        $elapsedMonthDays = max(1, $monthStart->diffInDays($today) + 1);
        $daysInMonth = now()->daysInMonth;

        $rangeDays = max(1, $startDate->diffInDays($endDate) + 1);

        // ===== RANGE =====
        $rangeService = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->sum('service_value');

        $rangeParts = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->sum('parts_value');

        $rangeTotal = $rangeService + $rangeParts;

        $previousRangeService = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$previousStartDate, $previousEndDate])
            ->sum('service_value');

        $previousRangeParts = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$previousStartDate, $previousEndDate])
            ->sum('parts_value');

        $previousRangeTotal = $previousRangeService + $previousRangeParts;

        // ===== TODAY =====
        $todayService = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', $today)
            ->sum('service_value');

        $todayParts = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', $today)
            ->sum('parts_value');

        $todayTotal = $todayService + $todayParts;

        // ===== MÊS CORRENTE / PROJEÇÃO =====
        $monthService = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$monthStart, $today->copy()->endOfDay()])
            ->sum('service_value');

        $monthParts = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$monthStart, $today->copy()->endOfDay()])
            ->sum('parts_value');

        $monthTotal = $monthService + $monthParts;
        $monthOrdersCount = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$monthStart, $today->copy()->endOfDay()])
            ->count();

        $projectedMonthService = ($monthService / $elapsedMonthDays) * $daysInMonth;
        $projectedMonthParts = ($monthParts / $elapsedMonthDays) * $daysInMonth;
        $projectedMonthTotal = $projectedMonthService + $projectedMonthParts;

        // ===== ORDERS =====
        $ordersCount = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->count();
        $previousOrdersCount = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$previousStartDate, $previousEndDate])
            ->count();
        $ordersTodayCount = $this->scopeOrdersQuery(Order::query())->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', $today)
            ->count();

        // ===== MÉDIA DIÁRIA =====
        $dailyAverageService = $rangeDays > 0 ? $rangeService / $rangeDays : 0;
        $dailyAverageParts = $rangeDays > 0 ? $rangeParts / $rangeDays : 0;
        $dailyAverageTotal = $dailyAverageService + $dailyAverageParts;
        $previousDailyAverageTotal = $rangeDays > 0 ? $previousRangeTotal / $rangeDays : 0;

        // ===== TICKET MÉDIO =====
        $averageTicketService = $ordersCount > 0 ? $rangeService / $ordersCount : 0;
        $averageTicketParts = $ordersCount > 0 ? $rangeParts / $ordersCount : 0;
        $averageTicketTotal = $averageTicketService + $averageTicketParts;
        $previousAverageTicketTotal = $previousOrdersCount > 0 ? $previousRangeTotal / $previousOrdersCount : 0;

        return response()->json([
            'range' => $timeRange,
            'kpis' => [

                'today_revenue' => [
                    'services' => $todayService,
                    'parts' => $todayParts,
                    'total' => $todayTotal,
                ],

                'month_projection' => [
                    'services' => $projectedMonthService,
                    'parts' => $projectedMonthParts,
                    'total' => $projectedMonthTotal,
                ],

                'range_revenue' => [
                    'services' => $rangeService,
                    'parts' => $rangeParts,
                    'total' => $rangeTotal,
                ],

                'daily_average' => [
                    'services' => $dailyAverageService,
                    'parts' => $dailyAverageParts,
                    'total' => $dailyAverageTotal,
                ],

                'average_ticket' => [
                    'services' => $averageTicketService,
                    'parts' => $averageTicketParts,
                    'total' => $averageTicketTotal,
                ],

                'orders_count' => $ordersCount,
                'orders_today_count' => $ordersTodayCount,
                'orders_month_count' => $monthOrdersCount,
                'month_revenue' => [
                    'services' => $monthService,
                    'parts' => $monthParts,
                    'total' => $monthTotal,
                ],
                'comparison' => [
                    'range_revenue' => $this->comparison($rangeTotal, $previousRangeTotal),
                    'daily_average' => $this->comparison($dailyAverageTotal, $previousDailyAverageTotal),
                    'average_ticket' => $this->comparison($averageTicketTotal, $previousAverageTicketTotal),
                ],
            ],
        ]);
    }

    public function financialRevenueChart($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);

        $orders = $this->scopeOrdersQuery(Order::query())->select(
            DB::raw('DATE(delivery_date) as date'),
            DB::raw('SUM(service_value) as services'),
            DB::raw('SUM(parts_value) as parts'),
            DB::raw('SUM(service_value + parts_value) as total')
        )
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('total', 'date')
            ->toArray();

        $services = $this->scopeOrdersQuery(Order::query())->select(
            DB::raw('DATE(delivery_date) as date'),
            DB::raw('SUM(service_value) as value')
        )
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $parts = $this->scopeOrdersQuery(Order::query())->select(
            DB::raw('DATE(delivery_date) as date'),
            DB::raw('SUM(parts_value) as value')
        )
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $period = CarbonPeriod::create($startDate, $endDate);

        $data = [];

        foreach ($period as $date) {

            $d = $date->format('Y-m-d');

            $data[] = [
                'date' => $d,
                'services' => $services[$d] ?? 0,
                'parts' => $parts[$d] ?? 0,
                'total' => $orders[$d] ?? 0,
            ];
        }

        return response()->json($data);
    }

    public function kpisSchedules($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);
        [$previousStartDate, $previousEndDate] = $this->getPreviousRange($startDate, $endDate);
        $today = now()->startOfDay();

        $rangeQuery = $this->scopeSchedulesQuery(Schedule::query())
            ->whereBetween('schedules', [$startDate, $endDate]);
        $previousRangeQuery = $this->scopeSchedulesQuery(Schedule::query())
            ->whereBetween('schedules', [$previousStartDate, $previousEndDate]);

        $total = (clone $rangeQuery)->count();
        $previousTotal = (clone $previousRangeQuery)->count();
        $completed = (clone $rangeQuery)->where('status', 3)->count();
        $previousCompleted = (clone $previousRangeQuery)->where('status', 3)->count();
        $open = (clone $rangeQuery)->where('status', 1)->count();
        $inProgress = (clone $this->scopeSchedulesQuery(Schedule::query()))->where('status', 2)->count();
        $overdue = (clone $this->scopeSchedulesQuery(Schedule::query()))
            ->whereIn('status', [1, 2])
            ->where('schedules', '<', $today)
            ->count();
        $todayCount = (clone $this->scopeSchedulesQuery(Schedule::query()))->whereDate('schedules', $today)->count();
        $tomorrowCount = (clone $this->scopeSchedulesQuery(Schedule::query()))->whereDate('schedules', $today->copy()->addDay())->count();
        $localPaymentTotal = (float) (clone $rangeQuery)
            ->where('local_payment_received', true)
            ->sum('local_payment_amount');
        $previousLocalPaymentTotal = (float) (clone $previousRangeQuery)
            ->where('local_payment_received', true)
            ->sum('local_payment_amount');

        $serviceDurations = (clone $rangeQuery)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->get(['check_in_at', 'check_out_at'])
            ->map(fn (Schedule $schedule) => $schedule->check_in_at && $schedule->check_out_at
                ? Carbon::parse($schedule->check_in_at)->diffInMinutes(Carbon::parse($schedule->check_out_at), false)
                : null)
            ->filter(fn ($minutes) => is_numeric($minutes) && $minutes > 0);
        $previousServiceDurations = (clone $previousRangeQuery)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->get(['check_in_at', 'check_out_at'])
            ->map(fn (Schedule $schedule) => $schedule->check_in_at && $schedule->check_out_at
                ? Carbon::parse($schedule->check_in_at)->diffInMinutes(Carbon::parse($schedule->check_out_at), false)
                : null)
            ->filter(fn ($minutes) => is_numeric($minutes) && $minutes > 0);
        $avgServiceMinutes = (float) ($serviceDurations->avg() ?? 0);
        $previousAvgServiceMinutes = (float) ($previousServiceDurations->avg() ?? 0);

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;
        $previousCompletionRate = $previousTotal > 0 ? round(($previousCompleted / $previousTotal) * 100, 1) : 0.0;

        return response()->json([
            'range' => $timeRange,
            'kpis' => [
                'total' => $total,
                'open' => $open,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'overdue' => $overdue,
                'today' => $todayCount,
                'tomorrow' => $tomorrowCount,
                'completion_rate' => $completionRate,
                'average_service_minutes' => round($avgServiceMinutes, 1),
                'local_payment_total' => $localPaymentTotal,
                'comparison' => [
                    'total' => $this->comparison($total, $previousTotal),
                    'completed' => $this->comparison($completed, $previousCompleted),
                    'completion_rate' => $this->comparison($completionRate, $previousCompletionRate),
                    'average_service_minutes' => $this->comparison($avgServiceMinutes, $previousAvgServiceMinutes),
                    'local_payment_total' => $this->comparison($localPaymentTotal, $previousLocalPaymentTotal),
                ],
            ],
        ]);
    }

    public function schedulesStatusChart($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);

        $schedules = $this->scopeSchedulesQuery(Schedule::query())
            ->selectRaw('
                DATE(schedules) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as completed
            ')
            ->whereBetween('schedules', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $data = $schedules->get($key);

            $result[] = [
                'date' => $key,
                'total' => (int) ($data->total ?? 0),
                'open' => (int) ($data->open ?? 0),
                'in_progress' => (int) ($data->in_progress ?? 0),
                'completed' => (int) ($data->completed ?? 0),
            ];
        }

        return response()->json($result);
    }

    public function kpisFinancialSales($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);
        [$previousStartDate, $previousEndDate] = $this->getPreviousRange($startDate, $endDate);
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth()->startOfDay();
        $elapsedMonthDays = max(1, $monthStart->diffInDays($today) + 1);
        $daysInMonth = now()->daysInMonth;

        $rangeDays = max(1, $startDate->diffInDays($endDate) + 1);

        $rangeRevenue = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_amount');

        $rangeCashExits = CashSession::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$startDate, $endDate])
            ->sum('manual_exits');

        $rangeRegisteredExpenses = Expense::query()
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        $rangeExpenses = $rangeCashExits + $rangeRegisteredExpenses;

        $previousRangeRevenue = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->sum('total_amount');

        $previousRangeCashExits = CashSession::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$previousStartDate, $previousEndDate])
            ->sum('manual_exits');

        $previousRangeRegisteredExpenses = Expense::query()
            ->whereBetween('expense_date', [$previousStartDate->toDateString(), $previousEndDate->toDateString()])
            ->sum('amount');

        $previousRangeExpenses = $previousRangeCashExits + $previousRangeRegisteredExpenses;

        $todayRevenue = Sale::where('status', 'completed')
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        $todayCashExits = CashSession::query()
            ->whereNotNull('closed_at')
            ->whereDate('closed_at', $today)
            ->sum('manual_exits');

        $todayRegisteredExpenses = Expense::query()
            ->whereDate('expense_date', $today)
            ->sum('amount');

        $todayExpenses = $todayCashExits + $todayRegisteredExpenses;

        $monthRevenue = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$monthStart, $today->copy()->endOfDay()])
            ->sum('total_amount');

        $monthSalesCount = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$monthStart, $today->copy()->endOfDay()])
            ->count();

        $salesCount = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        $previousSalesCount = Sale::where('status', 'completed')
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->count();

        $salesTodayCount = Sale::where('status', 'completed')
            ->whereDate('created_at', $today)
            ->count();

        $pendingSalesAmount = (float) Sale::query()
            ->where('status', 'completed')
            ->whereIn('financial_status', ['pending', 'partial'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('SUM(CASE WHEN COALESCE(total_amount, 0) - COALESCE(paid_amount, 0) > 0 THEN COALESCE(total_amount, 0) - COALESCE(paid_amount, 0) ELSE 0 END) as pending_total')
            ->value('pending_total');

        $cancelledSalesAmount = (float) Sale::query()
            ->where('status', 'cancelled')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_amount');

        $cancelledSalesCount = Sale::query()
            ->where('status', 'cancelled')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $topProducts = Sale::query()
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('parts', 'parts.id', '=', 'sale_items.part_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$startDate, $endDate])
            ->selectRaw('parts.name as name, SUM(sale_items.quantity) as quantity, SUM(sale_items.quantity * sale_items.unit_price) as total')
            ->groupBy('parts.id', 'parts.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'total' => (float) $item->total,
            ])
            ->values();

        $dailyAverage = $rangeDays > 0 ? $rangeRevenue / $rangeDays : 0;
        $dailyExpenseAverage = $rangeDays > 0 ? $rangeExpenses / $rangeDays : 0;
        $averageTicket = $salesCount > 0 ? $rangeRevenue / $salesCount : 0;
        $rangeProfit = $rangeRevenue - $rangeExpenses;
        $previousRangeProfit = $previousRangeRevenue - $previousRangeExpenses;
        $todayProfit = $todayRevenue - $todayExpenses;
        $monthProjectionRevenue = ($monthRevenue / $elapsedMonthDays) * $daysInMonth;
        $dailyProfitAverage = $rangeDays > 0 ? $rangeProfit / $rangeDays : 0;
        $previousDailyProfitAverage = $rangeDays > 0 ? $previousRangeProfit / $rangeDays : 0;
        $previousAverageTicket = $previousSalesCount > 0 ? $previousRangeRevenue / $previousSalesCount : 0;
        $paymentMethodsRaw = Sale::query()
            ->select('payment_method', DB::raw('SUM(total_amount) as total'))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();
        $paymentMethods = [
            'pix' => 0.0,
            'cartao' => 0.0,
            'dinheiro' => 0.0,
            'transferencia' => 0.0,
            'boleto' => 0.0,
            'outros' => 0.0,
        ];

        foreach ($paymentMethodsRaw as $method => $total) {
            $paymentMethods[$this->normalizePaymentMethod((string) $method)] += (float) $total;
        }

        return response()->json([
            'range' => $timeRange,
            'kpis' => [
                'today_revenue' => $todayRevenue,
                'today_expenses' => $todayExpenses,
                'today_profit' => $todayProfit,
                'month_projection_revenue' => $monthProjectionRevenue,
                'month_revenue' => $monthRevenue,
                'range_revenue' => $rangeRevenue,
                'range_expenses' => $rangeExpenses,
                'range_profit' => $rangeProfit,
                'range_cash_exits' => $rangeCashExits,
                'range_registered_expenses' => $rangeRegisteredExpenses,
                'daily_average' => $dailyAverage,
                'daily_expense_average' => $dailyExpenseAverage,
                'daily_profit_average' => $dailyProfitAverage,
                'average_ticket' => $averageTicket,
                'sales_count' => $salesCount,
                'sales_today_count' => $salesTodayCount,
                'sales_month_count' => $monthSalesCount,
                'pending_sales_amount' => $pendingSalesAmount,
                'cancelled_sales_amount' => $cancelledSalesAmount,
                'cancelled_sales_count' => $cancelledSalesCount,
                'top_products' => $topProducts,
                'payment_methods' => $paymentMethods,
                'comparison' => [
                    'range_revenue' => $this->comparison($rangeRevenue, $previousRangeRevenue),
                    'range_profit' => $this->comparison($rangeProfit, $previousRangeProfit),
                    'range_expenses' => $this->comparison($rangeExpenses, $previousRangeExpenses),
                    'daily_profit_average' => $this->comparison($dailyProfitAverage, $previousDailyProfitAverage),
                    'average_ticket' => $this->comparison($averageTicket, $previousAverageTicket),
                ],
            ],
        ]);
    }

    public function financialSalesRevenueChart($timeRange)
    {
        [$startDate, $endDate] = $this->getRange($timeRange);

        $totals = Sale::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(total_amount) as value')
        )
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $paid = Sale::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(total_amount) as value')
        )
            ->where('status', 'completed')
            ->where('financial_status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $pending = Sale::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(total_amount) as value')
        )
            ->where('status', 'completed')
            ->where('financial_status', 'pending')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $partial = Sale::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(total_amount) as value')
        )
            ->where('status', 'completed')
            ->where('financial_status', 'partial')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $cashExits = CashSession::select(
            DB::raw('DATE(closed_at) as date'),
            DB::raw('SUM(manual_exits) as value')
        )
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$startDate, $endDate])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $registeredExpenses = Expense::select(
            DB::raw('DATE(expense_date) as date'),
            DB::raw('SUM(amount) as value')
        )
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('date')
            ->pluck('value', 'date')
            ->toArray();

        $period = CarbonPeriod::create($startDate, $endDate);

        $data = [];

        foreach ($period as $date) {
            $d = $date->format('Y-m-d');

            $dayExpenses = ($cashExits[$d] ?? 0) + ($registeredExpenses[$d] ?? 0);

            $data[] = [
                'date' => $d,
                'total' => $totals[$d] ?? 0,
                'paid' => $paid[$d] ?? 0,
                'pending' => $pending[$d] ?? 0,
                'partial' => $partial[$d] ?? 0,
                'expenses' => $dayExpenses,
                'profit' => ($totals[$d] ?? 0) - $dayExpenses,
            ];
        }

        return response()->json($data);
    }

    public function kpisPurchases($timeRange)
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('purchase_orders') && Other::purchasesEnabled($user->tenant_id), 403);

        [$startDate, $endDate] = $this->getRange($timeRange);

        $receivedInRange = fn () => PurchaseOrder::query()
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->whereBetween('received_at', [$startDate, $endDate]);

        $totalSpent = (float) $receivedInRange()->sum('total_amount');
        $receivedCount = $receivedInRange()->count();
        $averageTicket = $receivedCount > 0 ? round($totalSpent / $receivedCount, 2) : 0.0;

        $pending = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT])
            ->selectRaw('count(*) as count, coalesce(sum(total_amount), 0) as total')
            ->first();

        $topSuppliers = PurchaseOrder::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.status', PurchaseOrder::STATUS_RECEIVED)
            ->whereBetween('purchase_orders.received_at', [$startDate, $endDate])
            ->groupBy('suppliers.id', 'suppliers.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get([
                'suppliers.id as id',
                'suppliers.name as name',
                DB::raw('sum(purchase_orders.total_amount) as total'),
                DB::raw('count(*) as orders_count'),
            ]);

        $topParts = PurchaseOrder::query()
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->join('parts', 'parts.id', '=', 'purchase_order_items.part_id')
            ->where('purchase_orders.status', PurchaseOrder::STATUS_RECEIVED)
            ->whereBetween('purchase_orders.received_at', [$startDate, $endDate])
            ->groupBy('parts.id', 'parts.name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get([
                'parts.id as id',
                'parts.name as name',
                DB::raw('sum(purchase_order_items.quantity) as quantity'),
                DB::raw('sum(purchase_order_items.quantity * purchase_order_items.unit_cost) as total'),
            ]);

        $lowStockCount = Part::query()->whereColumn('quantity', '<=', 'minimum_stock_level')->count();

        $monthsWindow = 6;
        $trendStart = now()->copy()->subMonths($monthsWindow - 1)->startOfMonth();
        $monthlyOrders = PurchaseOrder::query()
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->where('received_at', '>=', $trendStart)
            ->get(['received_at', 'total_amount']);

        $trend = [];
        for ($i = $monthsWindow - 1; $i >= 0; $i--) {
            $month = now()->copy()->subMonths($i)->startOfMonth();
            $total = $monthlyOrders->filter(fn ($po) => $po->received_at?->isSameMonth($month))->sum('total_amount');

            $trend[] = [
                'date' => $month->toDateString(),
                'total' => (float) $total,
            ];
        }

        return response()->json([
            'kpis' => [
                'total_spent' => $totalSpent,
                'received_count' => $receivedCount,
                'average_ticket' => $averageTicket,
                'pending_count' => (int) $pending->count,
                'pending_value' => (float) $pending->total,
                'low_stock_count' => $lowStockCount,
            ],
            'topSuppliers' => $topSuppliers,
            'topParts' => $topParts,
            'trend' => $trend,
        ]);
    }
}
