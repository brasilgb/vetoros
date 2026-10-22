<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Expense;
use App\Models\App\FiscalDocument;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\Sale;
use App\Models\App\Schedule;
use App\Support\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Teto de dias por relatório: evita que um período gigantesco (selecionado sem querer)
     * carregue e serialize dezenas de milhares de linhas de uma vez.
     */
    private const MAX_REPORT_RANGE_DAYS = 400;

    private function guardReportRange(Carbon $from, Carbon $to): void
    {
        if ($from->diffInDays($to) <= self::MAX_REPORT_RANGE_DAYS) {
            return;
        }

        // ValidationException (em vez de abort_if) garante que o Inertia trate isso como
        // erro de formulário normal — a tela recebe a mensagem em `errors`, não uma página de erro.
        throw ValidationException::withMessages([
            'to' => 'O período selecionado é muito longo para gerar este relatório de uma vez. Escolha um intervalo de até '
                .self::MAX_REPORT_RANGE_DAYS.' dias.',
        ]);
    }

    private function buildWarrantyReturnMeta(iterable $orders): array
    {
        $collection = collect($orders);
        $totalOrders = $collection->count();
        $warrantyReturns = $collection->where('is_warranty_return', true)->count();
        $threshold = Other::warrantyReturnAlertThreshold();
        $rate = $totalOrders > 0 ? round(($warrantyReturns / $totalOrders) * 100, 1) : 0.0;

        return [
            'warranty_return_threshold' => $threshold,
            'warranty_return_rate' => $rate,
            'warranty_return_alert' => $rate > $threshold,
            'warranty_returns' => $warrantyReturns,
        ];
    }

    private function canUseSalesReports(): bool
    {
        $user = Auth::user();
        $otherSetting = Other::query()->first();

        return (bool) ($user?->hasPermission('sales') && $otherSetting?->enablesales);
    }

    private function canUseFinanceReports(): bool
    {
        $user = Auth::user();
        $otherSetting = Other::query()->first();

        return (bool) ($user?->hasPermission('finance') && $otherSetting?->enable_finance);
    }

    private function canUseFiscalReports(): bool
    {
        $user = Auth::user();

        return (bool) $user?->hasPermission('fiscal_documents');
    }

    private function canUseQualityReports(): bool
    {
        $user = Auth::user();
        $otherSetting = Other::query()->first();

        return (bool) ($user?->hasPermission('reports') && $otherSetting?->show_quality_menu);
    }

    private function severityForRate(float $rate, float $threshold): string
    {
        if ($rate <= 5) {
            return 'Saudável';
        }

        if ($rate <= $threshold) {
            return 'Atenção';
        }

        return 'Crítico';
    }

    public function index(Request $request)
    {
        Gate::authorize('reports.view');

        return Inertia::render('app/reports/index');
    }

    public function equipmentFilters()
    {
        Gate::authorize('reports.view');

        $equipments = Equipment::query()
            ->orderBy('equipment')
            ->get(['id', 'equipment_number', 'equipment']);

        $models = Order::query()
            ->select('equipment_id', 'model')
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->distinct()
            ->orderBy('model')
            ->get()
            ->groupBy('equipment_id')
            ->map(fn ($items) => $items
                ->pluck('model')
                ->filter()
                ->unique()
                ->values()
                ->all()
            );

        return response()->json([
            'success' => true,
            'result' => [
                'equipments' => $equipments,
                'models_by_equipment' => $models,
                'models' => $models->flatten()->unique()->values(),
                'statuses' => OrderStatus::labels(),
            ],
        ]);
    }

    public function equipmentReport(Request $request)
    {
        Gate::authorize('reports.view');

        $data = $request->validate([
            'equipment_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:50'],
            'brand' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'integer', 'in:'.implode(',', OrderStatus::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! empty($data['equipment_id'])) {
            Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();
        }

        $model = trim((string) ($data['model'] ?? $data['brand'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 50);

        $query = Order::query()
            ->with([
                'customer:id,name,phone,whatsapp,email',
                'equipment:id,equipment',
                'user:id,name',
            ])
            ->when(! empty($data['equipment_id']), fn ($query) => $query->where('equipment_id', $data['equipment_id']))
            ->when($model !== '', fn ($query) => $query->where('model', $model))
            ->when(isset($data['status']), fn ($query) => $query->where('service_status', $data['status']))
            ->when(! empty($data['from']), fn ($query) => $query->where('created_at', '>=', Carbon::parse($data['from'])->startOfDay()))
            ->when(! empty($data['to']), fn ($query) => $query->where('created_at', '<=', Carbon::parse($data['to'])->endOfDay()))
            ->orderByDesc('created_at');

        $summaryQuery = clone $query;
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'result' => [
                'filters' => [
                    'equipment_id' => $data['equipment_id'] ?? null,
                    'model' => $model !== '' ? $model : null,
                    'status' => $data['status'] ?? null,
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                ],
                'summary' => [
                    'orders_count' => (clone $summaryQuery)->count(),
                    'service_cost_total' => (float) (clone $summaryQuery)->sum('service_cost'),
                    'budget_value_total' => (float) (clone $summaryQuery)->sum('budget_value'),
                    'warranty_returns' => (clone $summaryQuery)->where('is_warranty_return', true)->count(),
                ],
                'orders' => $orders->through(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'tracking_token' => $order->tracking_token,
                    'customer' => $order->customer,
                    'equipment' => $order->equipment,
                    'model' => $order->model,
                    'defect' => $order->defect,
                    'service_status' => $order->service_status,
                    'service_status_label' => OrderStatus::label($order->service_status),
                    'budget_value' => (float) ($order->budget_value ?? 0),
                    'service_cost' => (float) ($order->service_cost ?? 0),
                    'delivery_forecast' => $order->delivery_forecast,
                    'delivery_date' => $order->delivery_date?->toIso8601String(),
                    'created_at' => $order->created_at?->toIso8601String(),
                    'technician' => $order->user,
                ]),
            ],
        ]);
    }

    /**
     * Relatório em HTML: abre rápido mesmo com milhares de linhas e só gera o PDF se o
     * usuário pedir. Envia apenas as colunas que a tela usa, em vez de modelos inteiros.
     */
    public function preview(Request $request)
    {
        Gate::authorize('reports.view');

        $data = $request->validate([
            'type' => ['required', 'string', 'in:orders,customers,schedules,sales,parts,expenses,fiscal'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // Permissão antes do período: quem não pode ver o relatório não deve nem receber a mensagem dele.
        $allowed = match ($data['type']) {
            'orders' => (bool) Auth::user()?->hasPermission('orders'),
            'customers' => (bool) Auth::user()?->hasPermission('customers'),
            'schedules' => (bool) Auth::user()?->hasPermission('schedules'),
            'parts' => (bool) Auth::user()?->hasPermission('parts'),
            'sales' => $this->canUseSalesReports(),
            'expenses' => $this->canUseFinanceReports(),
            'fiscal' => $this->canUseFiscalReports(),
        };
        abort_unless($allowed, 403);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $props = [
            'type' => $data['type'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'reportData' => [],
            'reportMeta' => [],
            'error' => null,
        ];

        // Aqui a tela é aberta numa aba nova via GET: em vez de redirecionar de volta com erro,
        // a própria página de visualização mostra a mensagem.
        if ($from->diffInDays($to) > self::MAX_REPORT_RANGE_DAYS) {
            $props['error'] = 'O período selecionado é muito longo para gerar este relatório de uma vez. Escolha um intervalo de até '
                .self::MAX_REPORT_RANGE_DAYS.' dias.';

            return Inertia::render('app/reports/view', $props);
        }

        [$props['reportData'], $props['reportMeta']] = match ($data['type']) {
            'orders' => $this->ordersPreview($from, $to),
            'customers' => $this->customersPreview($from, $to),
            'schedules' => $this->schedulesPreview($from, $to),
            'sales' => $this->salesPreview($from, $to),
            'parts' => $this->partsPreview($from, $to),
            'expenses' => $this->expensesPreview($from, $to),
            'fiscal' => $this->fiscalPreview($from, $to),
        };

        return Inertia::render('app/reports/view', $props);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function ordersPreview(Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->select([
                'id',
                'order_number',
                'customer_id',
                'user_id',
                'model',
                'service_status',
                'is_warranty_return',
                'parts_value',
                'service_value',
                'created_at',
            ])
            ->with(['customer:id,name', 'user:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $rows = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer ? ['name' => $order->customer->name] : null,
            'user' => $order->user ? ['name' => $order->user->name] : null,
            'model' => $order->model,
            'service_status' => $order->service_status,
            'is_warranty_return' => (bool) $order->is_warranty_return,
            'parts_value' => (float) ($order->parts_value ?? 0),
            'service_value' => (float) ($order->service_value ?? 0),
        ])->all();

        return [$rows, $this->buildWarrantyReturnMeta($orders)];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function customersPreview(Carbon $from, Carbon $to): array
    {
        $rows = Customer::query()
            ->select(['id', 'customer_number', 'name', 'email', 'cpfcnpj', 'phone', 'created_at'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('customer_number')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'email' => $customer->email,
                'cpfcnpj' => $customer->cpfcnpj,
                'phone' => $customer->phone,
                'created_at' => $customer->created_at?->toIso8601String(),
            ])
            ->all();

        return [$rows, []];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function schedulesPreview(Carbon $from, Carbon $to): array
    {
        $rows = Schedule::query()
            ->select(['id', 'schedules_number', 'customer_id', 'user_id', 'order_id', 'schedules', 'status', 'created_at'])
            ->with(['customer:id,name', 'user:id,name', 'order:id,service_type,defect'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('schedules')
            ->get()
            ->map(fn (Schedule $schedule) => [
                'id' => $schedule->id,
                'schedules_number' => $schedule->schedules_number,
                'customer' => $schedule->customer ? ['name' => $schedule->customer->name] : null,
                'user' => $schedule->user ? ['name' => $schedule->user->name] : null,
                'schedules' => $schedule->schedules,
                'service' => $schedule->order?->service_type ?: $schedule->order?->defect,
                'status' => (int) $schedule->status,
                'created_at' => $schedule->created_at?->toIso8601String(),
            ])
            ->all();

        return [$rows, []];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function salesPreview(Carbon $from, Carbon $to): array
    {
        $rows = Sale::query()
            ->select(['id', 'sales_number', 'customer_id', 'status', 'payment_method', 'total_amount', 'created_at'])
            ->with('customer:id,name')
            ->withCount('items')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'sales_number' => $sale->sales_number,
                'customer' => $sale->customer ? ['name' => $sale->customer->name] : null,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'items_count' => (int) $sale->items_count,
                'total_amount' => (float) ($sale->total_amount ?? 0),
                'created_at' => $sale->created_at?->toIso8601String(),
            ])
            ->all();

        $meta = [
            'expenses_total' => (float) Expense::query()
                ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                ->sum('amount'),
        ];

        return [$rows, $meta];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function partsPreview(Carbon $from, Carbon $to): array
    {
        $rows = Part::query()
            ->select(['id', 'type', 'reference_number', 'name', 'manufacturer', 'quantity', 'minimum_stock_level', 'cost_price', 'sale_price'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('name')
            ->get()
            ->map(fn (Part $part) => [
                'id' => $part->id,
                'type' => $part->type,
                'reference_number' => $part->reference_number,
                'name' => $part->name,
                'manufacturer' => $part->manufacturer,
                'quantity' => (float) ($part->quantity ?? 0),
                'minimum_stock_level' => (float) ($part->minimum_stock_level ?? 0),
                'cost_price' => (float) ($part->cost_price ?? 0),
                'sale_price' => (float) ($part->sale_price ?? 0),
            ])
            ->all();

        return [$rows, []];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function expensesPreview(Carbon $from, Carbon $to): array
    {
        $rows = Expense::query()
            ->select(['id', 'expense_number', 'created_by', 'expense_date', 'description', 'category', 'amount'])
            ->with('createdBy:id,name')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('expense_date')
            ->orderByDesc('expense_number')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'expense_number' => $expense->expense_number,
                'expense_date' => $expense->expense_date instanceof \DateTimeInterface
                    ? $expense->expense_date->format('Y-m-d')
                    : $expense->expense_date,
                'description' => $expense->description,
                'category' => $expense->category,
                'amount' => (float) ($expense->amount ?? 0),
                'created_by' => $expense->createdBy ? ['name' => $expense->createdBy->name] : null,
            ])
            ->all();

        return [$rows, []];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function fiscalPreview(Carbon $from, Carbon $to): array
    {
        $documents = FiscalDocument::query()
            ->select(['id', 'type', 'provider', 'documentable_type', 'documentable_id', 'number', 'status', 'issued_at', 'registered_by', 'created_at'])
            ->with('registeredBy:id,name')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $rows = $documents->map(fn (FiscalDocument $document) => [
            'id' => $document->id,
            'type' => $document->type,
            'provider' => $document->provider,
            'documentable_type' => $document->documentable_type,
            'documentable_id' => $document->documentable_id,
            'number' => $document->number,
            'status' => $document->status,
            'issued_at' => $document->issued_at instanceof \DateTimeInterface
                ? $document->issued_at->toIso8601String()
                : $document->issued_at,
            'registered_by' => $document->registeredBy ? ['name' => $document->registeredBy->name] : null,
        ])->all();

        $meta = [
            'documents_count' => $documents->count(),
            'manual_count' => $documents->where('provider', 'manual')->count(),
            'integration_count' => $documents->where('provider', '!=', 'manual')->count(),
            'nfe_count' => $documents->where('type', 'nfe')->count(),
            'nfse_count' => $documents->where('type', 'nfse')->count(),
            'error_count' => $documents->where('status', 'error')->count(),
        ];

        return [$rows, $meta];
    }

    public function store(Request $request)
    {
        Gate::authorize('reports.view');

        $type = $request->input('type');
        $from = Carbon::parse($request->input('from'))->startOfDay();
        $to = Carbon::parse($request->input('to'))->endOfDay();
        $this->guardReportRange($from, $to);
        $reportMeta = [];

        switch ($type) {
            case 'orders':
                abort_unless(Auth::user()?->hasPermission('orders'), 403);
                $data = Order::with(['customer', 'equipment', 'user'])
                    ->whereBetween('created_at', [$from, $to])
                    ->orderBy('created_at', 'desc')
                    ->get();
                $reportMeta = [
                    ...$reportMeta,
                    ...$this->buildWarrantyReturnMeta($data),
                ];
                break;

            case 'customers':
                abort_unless(Auth::user()?->hasPermission('customers'), 403);
                $data = Customer::whereBetween('created_at', [$from, $to])->get();
                break;

            case 'schedules':
                abort_unless(Auth::user()?->hasPermission('schedules'), 403);
                $data = Schedule::with(['customer', 'user', 'order'])
                    ->whereBetween('created_at', [$from, $to])
                    ->get()
                    ->map(function (Schedule $schedule) {
                        $schedule->setAttribute('service', $schedule->order?->service_type ?: $schedule->order?->defect);

                        return $schedule;
                    });
                break;

            case 'sales':
                if (! $this->canUseSalesReports()) {
                    abort(403, 'Relatórios de vendas estão desabilitados.');
                }

                $data = Sale::with('customer')
                    ->withCount('items')
                    ->whereBetween('created_at', [$from, $to])
                    ->get();

                $reportMeta['expenses_total'] = (float) Expense::query()
                    ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                    ->sum('amount');
                break;

            case 'parts':
                abort_unless(Auth::user()?->hasPermission('parts'), 403);
                $data = Part::query()
                    ->withCount('part_movements')
                    ->whereBetween('created_at', [$from, $to])
                    ->orderBy('name')
                    ->get();
                break;

            case 'cashier':
                if (! $this->canUseFinanceReports()) {
                    abort(403, 'Relatórios de caixa estão desabilitados.');
                }

                $data = CashSession::query()
                    ->with([
                        'openedBy:id,name',
                        'closedBy:id,name',
                        'sales:id,cash_session_id,total_amount,payment_method,status',
                        'orderPayments:id,cash_session_id,amount,payment_method',
                        'withdrawals.user:id,name',
                        'withdrawals.cancelledBy:id,name',
                    ])
                    ->where('status', 'closed')
                    ->whereBetween('closed_at', [$from, $to])
                    ->orderByDesc('closed_at')
                    ->get();

                $reportMeta['sessions_count'] = $data->count();
                $reportMeta['opening_total'] = (float) $data->sum('opening_balance');
                $reportMeta['completed_sales_total'] = (float) $data->sum('total_completed_sales');
                $reportMeta['order_payments_total'] = (float) $data->sum('total_order_payments');
                $reportMeta['manual_entries_total'] = (float) $data->sum('manual_entries');
                $reportMeta['manual_exits_total'] = (float) $data->sum('manual_exits');
                $reportMeta['withdrawals_total'] = (float) $data->sum(
                    fn (CashSession $session): float => (float) $session->withdrawals->whereNull('cancelled_at')->sum('amount')
                );
                $reportMeta['expected_total'] = (float) $data->sum('expected_balance');
                $reportMeta['closing_total'] = (float) $data->sum('closing_balance');
                $reportMeta['difference_total'] = (float) $data->sum('difference');
                $reportMeta['sales_by_method'] = [
                    'pix' => 0.0,
                    'cartao' => 0.0,
                    'dinheiro' => 0.0,
                    'transferencia' => 0.0,
                    'boleto' => 0.0,
                ];
                $reportMeta['order_payments_by_method'] = [
                    'pix' => 0.0,
                    'cartao' => 0.0,
                    'dinheiro' => 0.0,
                    'transferencia' => 0.0,
                    'boleto' => 0.0,
                ];

                foreach ($data as $session) {
                    foreach (($session->sales ?? collect()) as $sale) {
                        if (($sale->status ?? null) !== 'completed') {
                            continue;
                        }
                        $method = strtolower((string) ($sale->payment_method ?? ''));
                        if (array_key_exists($method, $reportMeta['sales_by_method'])) {
                            $reportMeta['sales_by_method'][$method] += (float) ($sale->total_amount ?? 0);
                        }
                    }

                    foreach (($session->orderPayments ?? collect()) as $payment) {
                        $method = strtolower((string) ($payment->payment_method ?? ''));
                        if (array_key_exists($method, $reportMeta['order_payments_by_method'])) {
                            $reportMeta['order_payments_by_method'][$method] += (float) ($payment->amount ?? 0);
                        }
                    }
                }
                break;

            case 'quality':
                if (! $this->canUseQualityReports()) {
                    abort(403, 'Relatórios de gestão estão desabilitados.');
                }

                $data = Order::query()
                    ->with([
                        'equipment:id,equipment',
                        'user:id,name',
                        'customer:id,name',
                        'customerFeedbackRecoveryAssignee:id,name',
                    ])
                    ->whereBetween('created_at', [$from, $to])
                    ->orderByDesc('created_at')
                    ->get();

                $threshold = Other::warrantyReturnAlertThreshold();
                $warrantyOrders = $data->where('is_warranty_return', true)->values();
                $deliveredOrders = $data->where('service_status', OrderStatus::DELIVERED)->values();
                $feedbackResponses = $deliveredOrders->filter(fn ($order) => ! is_null($order->customer_feedback_submitted_at))->values();
                $lowFeedbackOrders = $feedbackResponses
                    ->filter(fn ($order) => (int) ($order->customer_feedback_rating ?? 0) <= 3)
                    ->sortBy('customer_feedback_rating')
                    ->values();

                $totalOrders = $data->count();
                $warrantyReturns = $warrantyOrders->count();
                $warrantyRate = $totalOrders > 0 ? round(($warrantyReturns / $totalOrders) * 100, 1) : 0.0;
                $feedbackAverageRating = round((float) ($feedbackResponses->avg('customer_feedback_rating') ?? 0), 1);
                $feedbackResponseRate = $deliveredOrders->count() > 0
                    ? round(($feedbackResponses->count() / $deliveredOrders->count()) * 100, 1)
                    : 0.0;

                $reportMeta = [
                    'summary' => [
                        'total_orders' => $totalOrders,
                        'warranty_returns' => $warrantyReturns,
                        'warranty_return_rate' => $warrantyRate,
                        'warranty_return_threshold' => $threshold,
                        'severity' => $this->severityForRate($warrantyRate, $threshold),
                        'feedback_responses' => $feedbackResponses->count(),
                        'feedback_average_rating' => $feedbackAverageRating,
                        'feedback_response_rate' => $feedbackResponseRate,
                        'low_feedbacks' => $lowFeedbackOrders->count(),
                        'recovery_pending' => $lowFeedbackOrders->filter(
                            fn ($order) => ($order->customer_feedback_recovery_status ?: 'pending') === 'pending'
                        )->count(),
                        'recovery_in_progress' => $lowFeedbackOrders->filter(
                            fn ($order) => ($order->customer_feedback_recovery_status ?: 'pending') === 'in_progress'
                        )->count(),
                        'recovery_resolved' => $lowFeedbackOrders->filter(
                            fn ($order) => ($order->customer_feedback_recovery_status ?: 'pending') === 'resolved'
                        )->count(),
                    ],
                    'top_equipments' => $warrantyOrders
                        ->groupBy(fn ($order) => $order->equipment?->equipment ?: 'Equipamento não informado')
                        ->map(fn ($items, $label) => ['label' => $label, 'total' => $items->count()])
                        ->sortByDesc('total')
                        ->values()
                        ->take(5)
                        ->all(),
                    'top_defects' => $warrantyOrders
                        ->groupBy(fn ($order) => trim((string) ($order->defect ?: 'Defeito não informado')) ?: 'Defeito não informado')
                        ->map(fn ($items, $label) => ['label' => $label, 'total' => $items->count()])
                        ->sortByDesc('total')
                        ->values()
                        ->take(5)
                        ->all(),
                    'top_technicians' => $warrantyOrders
                        ->groupBy(fn ($order) => $order->user?->name ?: 'Não definido')
                        ->map(fn ($items, $label) => ['label' => $label, 'total' => $items->count()])
                        ->sortByDesc('total')
                        ->values()
                        ->take(5)
                        ->all(),
                    'low_feedback_orders' => $lowFeedbackOrders
                        ->take(8)
                        ->map(fn ($order) => [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'customer' => $order->customer?->name ?: 'Cliente não informado',
                            'rating' => (int) ($order->customer_feedback_rating ?? 0),
                            'comment' => $order->customer_feedback_comment,
                            'submitted_at' => $order->customer_feedback_submitted_at?->toIso8601String(),
                            'recovery_status' => $order->customer_feedback_recovery_status ?: 'pending',
                            'recovery_assigned_to' => $order->customerFeedbackRecoveryAssignee?->name,
                        ])
                        ->all(),
                ];
                break;

            case 'expenses':
                if (! $this->canUseFinanceReports()) {
                    abort(403, 'Relatórios de despesas estão desabilitados.');
                }

                $data = Expense::with('createdBy:id,name')
                    ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                    ->orderByDesc('expense_date')
                    ->orderByDesc('expense_number')
                    ->get();
                break;

            case 'fiscal':
                if (! $this->canUseFiscalReports()) {
                    abort(403, 'Relatórios fiscais estão desabilitados.');
                }

                $data = FiscalDocument::query()
                    ->with('registeredBy:id,name')
                    ->whereBetween('created_at', [$from, $to])
                    ->orderByDesc('created_at')
                    ->get();

                $reportMeta = [
                    'documents_count' => $data->count(),
                    'manual_count' => $data->where('provider', 'manual')->count(),
                    'integration_count' => $data->where('provider', '!=', 'manual')->count(),
                    'nfe_count' => $data->where('type', 'nfe')->count(),
                    'nfse_count' => $data->where('type', 'nfse')->count(),
                    'error_count' => $data->where('status', 'error')->count(),
                ];
                break;

            default:
                $data = collect();
        }

        return Inertia::render('app/reports/index', [
            'reportData' => $data,
            'reportMeta' => $reportMeta,
            'type' => $type,
        ]);
    }
}
