<?php

namespace App\Http\Controllers\App;

use App\Events\OrderCreated;
use App\Events\OrderLifecycleCreated;
use App\Events\OrderLifecycleStatusChanged;
use App\Events\OrderPaymentRegistered;
use App\Events\OrderPaymentRemoved;
use App\Events\OrderStatusUpdated;
use App\Exceptions\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\FiscalDocument;
use App\Models\App\Order;
use App\Models\App\OrderLog;
use App\Models\App\OrderMessage;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\Schedule;
use App\Models\App\WhatsappMessage;
use App\Models\User;
use App\Services\FinancialReceivableService;
use App\Services\FiscalDocumentService;
use App\Services\OperationalAuditService;
use App\Services\OrderBudgetService;
use App\Services\OrderCommunicationContextService;
use App\Services\OrderDeadlineService;
use App\Services\OrderItemSyncService;
use App\Services\OrderMessageService;
use App\Services\OrderNotificationService;
use App\Services\OrderPartsService;
use App\Services\OrderPaymentService;
use App\Services\OrderStatusService;
use App\Services\OrderTechnicianAssignmentService;
use App\Services\OrderTotalsService;
use App\Services\TechnicianCommissionService;
use App\Services\WhatsAppService;
use App\Support\Ean13;
use App\Support\OrderActor;
use App\Support\OrderSignature;
use App\Support\OrderStatus;
use App\Support\Pagination;
use App\Support\TenantSequence;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function __construct(
        private readonly OperationalAuditService $operationalAuditService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderStatusService $orderStatusService,
        private readonly OrderTechnicianAssignmentService $orderTechnicianAssignmentService,
        private readonly FinancialReceivableService $financialReceivableService,
        private readonly TechnicianCommissionService $technicianCommissionService,
        private readonly OrderItemSyncService $orderItemSyncService,
        private readonly OrderPartsService $orderPartsService,
        private readonly OrderTotalsService $orderTotalsService,
        private readonly OrderDeadlineService $orderDeadlineService,
        private readonly OrderMessageService $orderMessageService,
        private readonly OrderBudgetService $orderBudgetService,
        private readonly FiscalDocumentService $fiscalDocumentService,
        private readonly OrderNotificationService $orderNotificationService,
        private readonly WhatsAppService $whatsAppService,
        private readonly OrderCommunicationContextService $orderCommunicationContextService,
    ) {}

    private function shouldSendCustomerMailer(Order $order, ?string $customerEmail): bool
    {
        $email = trim((string) ($customerEmail ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->orderNotificationService->canSendToCustomer($order, $customerEmail);
    }

    private function financeEnabled(): bool
    {
        if (! $this->currentUser()?->hasPermission('finance')) {
            return false;
        }

        return Other::financeEnabled($this->currentUser()?->tenant_id);
    }

    private function canConfirmMobilePayment(): bool
    {
        $user = $this->currentUser();

        return $user && ($user->isRoot() || $user->isAdministrator() || $user->isOperator());
    }

    private function appendPaymentReminderAvailability(Order $order): Order
    {
        $order->setAttribute(
            'can_send_payment_reminder',
            $this->shouldSendCustomerMailer($order, $order->customer?->email)
        );
        $order->setAttribute(
            'can_send_budget_follow_up',
            (int) $order->service_status === OrderStatus::BUDGET_GENERATED
                && $this->shouldSendCustomerMailer($order, $order->customer?->email)
        );

        return $order;
    }

    private function latestCommunicationLog(Order $order): ?OrderLog
    {
        if ($order->relationLoaded('logs')) {
            return $order->logs
                ->whereIn('action', ['payment_reminder_sent', 'budget_follow_up_sent'])
                ->sortByDesc('created_at')
                ->first();
        }

        return $order->logs()
            ->whereIn('action', ['payment_reminder_sent', 'budget_follow_up_sent'])
            ->latest('created_at')
            ->first();
    }

    private function appendLastCommunication(Order $order): Order
    {
        $log = $this->latestCommunicationLog($order);

        if (! $log) {
            $order->setAttribute('last_communication', null);

            return $order;
        }

        $data = is_array($log->data) ? $log->data : [];

        $order->setAttribute('last_communication', [
            'action' => $log->action,
            'trigger' => $data['trigger'] ?? null,
            'channel' => $data['channel'] ?? null,
            'recipient' => $data['recipient'] ?? null,
            'is_overdue' => (bool) ($data['is_overdue'] ?? false),
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return $order;
    }

    private function communicationThresholdDays(): int
    {
        return $this->orderCommunicationContextService->communicationThresholdDays($this->currentUser()?->tenant_id);
    }

    private function customerFeedbackRequestThreshold(): Carbon
    {
        return $this->orderCommunicationContextService->customerFeedbackRequestThreshold($this->currentUser()?->tenant_id);
    }

    private function customerFeedbackExpirationThreshold(): Carbon
    {
        return $this->orderCommunicationContextService->customerFeedbackExpirationThreshold($this->currentUser()?->tenant_id);
    }

    private function isBudgetFollowUpOrder(Order $order): bool
    {
        return $this->orderCommunicationContextService->isBudgetFollowUp($order, $this->currentUser()?->tenant_id);
    }

    private function isPendingPaymentOrder(Order $order, ?array $paymentSummary = null): bool
    {
        $paymentSummary ??= $this->buildPaymentSummary($order);
        $remaining = (float) ($paymentSummary['remaining'] ?? 0);
        $tenantId = $order->tenant_id ? (int) $order->tenant_id : $this->currentUser()?->tenant_id;

        return $this->orderCommunicationContextService->isPendingPayment($order, $tenantId, $remaining);
    }

    private function communicationDaysPending(Order $order): int
    {
        return $this->orderCommunicationContextService->communicationDaysPending($order);
    }

    private function appendCommunicationFlags(Order $order): Order
    {
        $paymentSummary = $this->buildPaymentSummary($order);

        $order->setAttribute('communication_days_pending', $this->communicationDaysPending($order));
        $order->setAttribute('budget_follow_up', $this->isBudgetFollowUpOrder($order));
        $order->setAttribute('pending_payment_follow_up', $this->isPendingPaymentOrder($order, $paymentSummary));

        return $this->appendLastCommunication($order);
    }

    private function currentUser(): ?User
    {
        $user = Auth::user() ?? Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    private function normalizeMoneyValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $raw = trim((string) $value);

        // Suporta "1.234,56" e "1234.56"
        $normalized = str_contains($raw, ',')
            ? str_replace(',', '.', str_replace('.', '', $raw))
            : str_replace(',', '', $raw);

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeMoneyFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $this->normalizeMoneyValue($value);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    private function normalizeDeliveryDateForStatus(array $data): array
    {
        $status = (int) ($data['service_status'] ?? 0);

        if ($status !== OrderStatus::DELIVERED) {
            $data['delivery_date'] = null;

            return $data;
        }

        if (empty($data['delivery_date'])) {
            $data['delivery_date'] = now()->toDateTimeString();
        }

        return $data;
    }

    private function warrantySourceOrder(array $data, ?Order $currentOrder = null): ?Order
    {
        if (! filter_var($data['is_warranty_return'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $query = Order::query()
            ->whereKey($data['warranty_source_order_id'] ?? 0)
            ->where('customer_id', $data['customer_id'] ?? 0)
            ->where('equipment_id', $data['equipment_id'] ?? 0)
            ->whereNotNull('delivery_date')
            ->whereNotNull('warranty_expires_at')
            ->where(function ($query) use ($currentOrder) {
                $query->where('warranty_expires_at', '>=', now())
                    ->when(
                        $currentOrder?->warranty_source_order_id,
                        fn ($query, $sourceId) => $query->orWhere($query->getModel()->getQualifiedKeyName(), $sourceId)
                    );
            });

        if ($currentOrder) {
            $query->whereKeyNot($currentOrder->id);
        }

        $sourceOrder = $query->first();

        if (! $sourceOrder) {
            throw ValidationException::withMessages([
                'warranty_source_order_id' => 'Selecione uma OS de origem válida, entregue para o mesmo cliente e equipamento, com garantia ativa.',
            ]);
        }

        return $sourceOrder;
    }

    private function warrantySourceOptions(?int $currentSourceOrderId = null, ?int $excludeOrderId = null)
    {
        return Order::query()
            ->with(['customer:id,name', 'equipment:id,equipment'])
            ->whereNotNull('delivery_date')
            ->whereNotNull('warranty_expires_at')
            ->where(function ($query) use ($currentSourceOrderId) {
                $query->where('warranty_expires_at', '>=', now())
                    ->when(
                        $currentSourceOrderId,
                        fn ($query) => $query->orWhere($query->getModel()->getQualifiedKeyName(), $currentSourceOrderId)
                    );
            })
            ->when($excludeOrderId, fn ($query) => $query->whereKeyNot($excludeOrderId))
            ->orderByDesc('delivery_date')
            ->get(['id', 'order_number', 'customer_id', 'equipment_id', 'model', 'delivery_date', 'warranty_expires_at']);
    }

    private function activeOrderOptions()
    {
        return $this->scopeOrdersQuery(Order::query())
            ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED])
            ->latest('updated_at')
            ->get(['id', 'order_number', 'customer_id', 'equipment_id', 'model', 'service_status', 'updated_at']);
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

    private function logOperationalAudit(string $action, Order $order, array $data = []): void
    {
        $this->operationalAuditService->record($action, 'order', $order, $this->currentUser()?->id, $data);
    }

    /**
     * Quantidades finais de peças de estoque enviadas pelo formulário; null quando o
     * formulário não enviou a lista (peças não mudam).
     *
     * @return array<int, int>|null
     */
    private function requestedPartQuantities(array $data): ?array
    {
        if (! isset($data['allparts']) || ! is_array($data['allparts'])) {
            return null;
        }

        $quantities = [];
        foreach ($data['allparts'] as $part) {
            $quantities[(int) $part['part_id']] = (int) $part['quantity'];
        }

        return $quantities;
    }

    /**
     * Valor de peças avulsas (não estocadas). Clientes antigos que não enviam
     * manual_parts_value: sem peças de estoque, o "Valor das peças" digitado é exatamente
     * esse valor (comportamento anterior); com peças de estoque, mantém o valor já gravado.
     *
     * @param  array<int, int>|null  $nextParts
     */
    private function resolveManualPartsValue(Request $request, array $data, Order $order, ?array $nextParts): string
    {
        if ($request->has('manual_parts_value')) {
            return $this->normalizeMoneyValue($data['manual_parts_value']);
        }

        $hasStockParts = $nextParts !== null
            ? array_filter($nextParts, fn (int $quantity): bool => $quantity > 0) !== []
            : $order->orderParts()->exists();

        return $hasStockParts
            ? (string) ($order->manual_parts_value ?? '0.00')
            : $this->normalizeMoneyValue($data['parts_value'] ?? 0);
    }

    private function buildPaymentSummary(Order $order): array
    {
        $partsValue = $this->roundMoney((float) ($order->parts_value ?? 0));
        $serviceValue = $this->roundMoney((float) ($order->service_value ?? 0));
        $totalOrder = $this->roundMoney((float) ($order->service_cost ?? 0));
        $totalPaid = $this->roundMoney((float) $order->orderPayments->sum('amount'));
        $remaining = $this->roundMoney(max(0, $totalOrder - $totalPaid));

        return [
            'parts_value' => $partsValue,
            'service_value' => $serviceValue,
            'total_order' => $totalOrder,
            'total_paid' => $totalPaid,
            'remaining' => $remaining,
        ];
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

    // Display and linting order for id
    public function allOrder()
    {
        $this->authorize('viewAny', Order::class);

        $dashData = [
            'numorder' => $this->scopeOrdersQuery(Order::query())->count(),
            'numabertas' => $this->scopeOrdersQuery(Order::where('service_status', OrderStatus::OPEN))->count(),
            'numgerados' => $this->scopeOrdersQuery(Order::where('service_status', OrderStatus::BUDGET_GENERATED))->count(),
            'numaprovados' => $this->scopeOrdersQuery(Order::where('service_status', OrderStatus::BUDGET_APPROVED))->count(),
            'numconcluidosca' => $this->scopeOrdersQuery(Order::where('service_status', OrderStatus::CUSTOMER_NOTIFIED))->count(),
            'numconcluidoscn' => $this->scopeOrdersQuery(Order::whereIn('service_status', [
                OrderStatus::SERVICE_COMPLETED,
            ]))->count(),
        ];

        return [
            'success' => true,
            'result' => $dashData,
        ];
    }

    // Display and linting order for id
    public function getOrder($order)
    {
        $this->authorize('viewAny', Order::class);

        $query = $this->scopeOrdersQuery(Order::where('order_number', $order))->with('customer')->with('equipment')->get();

        return [
            'success' => true,
            'result' => $this->withSignatureStatus($query),
        ];
    }

    // Display and listing customers for id order
    public function getOrderCli($customer)
    {
        $this->authorize('viewAny', Order::class);

        $query = $this->scopeOrdersQuery(Order::where('customer_id', $customer))->with('customer')->with('equipment')->get();

        return [
            'success' => true,
            'result' => $this->withSignatureStatus($query),
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function withSignatureStatus($orders)
    {
        return $orders->map(function (Order $order) {
            $order->setAttribute('has_customer_signature', OrderSignature::exists($order));
            $order->setAttribute('customer_signature_url', OrderSignature::url($order));

            return $order;
        });
    }

    /**
     * Recebe a assinatura digital do cliente (PNG em base64) e vincula a uma OS já existente.
     * Usada pelo app vetor-atendimento: o técnico seleciona o cliente e a ordem, e o cliente
     * assina na tela do tablet/celular.
     */
    public function storeSignature(Request $request, $order): array
    {
        $order = $this->scopeOrdersQuery(Order::where('order_number', $order))->firstOrFail();
        $this->authorize('update', $order);

        $validated = $request->validate([
            'customer_signature' => ['required', 'string'],
        ]);

        if (! OrderSignature::store($order, $validated['customer_signature'])) {
            throw ValidationException::withMessages([
                'customer_signature' => 'Assinatura inválida.',
            ]);
        }

        return [
            'success' => true,
            'message' => 'Assinatura registrada com sucesso.',
            'result' => [
                'order_number' => $order->order_number,
                'customer_signature_url' => OrderSignature::url($order),
            ],
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $feedbackThreshold = $this->customerFeedbackRequestThreshold();
        $feedbackExpirationThreshold = $this->customerFeedbackExpirationThreshold();

        $status = $request->status;
        $search = $request->search;
        $barcodeSearch = is_string($search) ? Ean13::normalize($search) : null;
        $eanPayload = $barcodeSearch !== null ? Ean13::payload($barcodeSearch) : null;
        $orderNumberSearch = $eanPayload !== null ? (string) ((int) $eanPayload) : $search;
        $filter = $request->filter;

        $query = $this->scopeOrdersQuery(Order::query())->orderBy('id', 'DESC');

        if ($status) {
            $query->where('service_status', $status);
        }

        if ($filter === 'due_48h') {
            $today = Carbon::today();
            $tomorrow = Carbon::tomorrow();

            $query->whereNotNull('delivery_forecast')
                ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED])
                ->whereBetween('delivery_forecast', [$today->toDateString(), $tomorrow->toDateString()]);
        } elseif ($filter === 'overdue') {
            $query->whereNotNull('delivery_forecast')
                ->whereDate('delivery_forecast', '<', Carbon::today())
                ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED]);
        } elseif ($filter === 'unassigned') {
            $query->whereNull('user_id')
                ->whereNotIn('service_status', [OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED, OrderStatus::DELIVERED]);
        } elseif ($filter === 'awaiting_pickup') {
            $query->where('service_status', OrderStatus::CUSTOMER_NOTIFIED);
        } elseif ($filter === 'feedback') {
            $query->where('service_status', OrderStatus::DELIVERED)
                ->whereNotNull('delivery_date')
                ->where('delivery_date', '<=', $feedbackThreshold)
                ->where('delivery_date', '>', $feedbackExpirationThreshold)
                ->whereNull('customer_feedback_submitted_at')
                ->whereNull('customer_feedback_request_expired_at');
        } elseif ($filter === 'financial_open') {
            $query
                ->where('service_status', OrderStatus::DELIVERED)
                ->whereRaw(
                    'EXISTS (SELECT 1 FROM others WHERE others.tenant_id = orders.tenant_id AND others.enable_finance = 1)'
                )
                ->whereRaw(
                    '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
                );
        } elseif ($filter === 'budget_follow_up') {
            $query->where('service_status', OrderStatus::BUDGET_GENERATED)
                ->whereBudgetPendingBefore(now()->subDays($this->communicationThresholdDays()));
        } elseif ($filter === 'pending_payment_follow_up') {
            $query
                ->where('service_status', OrderStatus::DELIVERED)
                ->whereNotNull('delivery_date')
                ->where('delivery_date', '<=', now()->subDays($this->communicationThresholdDays()))
                ->whereRaw(
                    'EXISTS (SELECT 1 FROM others WHERE others.tenant_id = orders.tenant_id AND others.enable_finance = 1)'
                )
                ->whereRaw(
                    '(COALESCE(orders.service_cost, 0) - COALESCE((SELECT SUM(op.amount) FROM order_payments op WHERE op.order_id = orders.id), 0)) > 0.009'
                );
        } elseif ($filter === 'warranty_return') {
            $query->where('is_warranty_return', true);
        } elseif ($filter === 'active_warranty') {
            $query->whereNotNull('delivery_date')
                ->whereNotNull('warranty_expires_at')
                ->where('warranty_expires_at', '>=', now());
        }

        if ($search) {
            $query->where(function ($q) use ($orderNumberSearch, $search, $barcodeSearch) {
                $q->where('order_number', $orderNumberSearch)
                    ->when($barcodeSearch !== null, fn ($barcodeQuery) => $barcodeQuery->orWhere('barcode', $barcodeSearch))
                    ->orWhere('model', 'like', "%$search%")
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%$search%")
                            ->orWhere('cpfcnpj', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('whatsapp', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('equipment', function ($subQuery) use ($search) {
                        $subQuery->where('equipment', 'like', "%$search%");
                    });
            });
        }

        $orders = $query
            ->with([
                'equipment',
                'customer',
                'orderPayments:id,order_id,amount',
                'logs' => fn ($logsQuery) => $logsQuery->whereIn('action', ['payment_reminder_sent', 'budget_follow_up_sent']),
            ])
            ->withMax('statusHistory as status_changed_at', 'created_at')
            ->withCount('images')
            ->withSum('orderPayments as total_paid', 'amount')
            ->paginate(Pagination::perPage())
            ->withQueryString();
        $orders->setCollection(
            $orders->getCollection()->map(function (Order $order) {
                $order = $this->appendPaymentReminderAvailability($order);
                $order->setAttribute('public_access_key_value', $order->public_access_key);

                return $this->appendCommunicationFlags($order);
            })
        );
        $whats = WhatsappMessage::first();

        $feedbackOrders = $this->scopeOrdersQuery(Order::query())
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->where('delivery_date', '<=', $feedbackThreshold)
            ->where('delivery_date', '>', $feedbackExpirationThreshold)
            ->whereNull('customer_feedback_submitted_at')
            ->whereNull('customer_feedback_request_expired_at')
            ->get(['id', 'order_number']);

        return Inertia::render('app/orders/index', [
            'orders' => $orders,
            'whats' => $whats,
            'feedback' => $feedbackOrders,
            'search' => $request->search,
            'status' => $status,
            'filter' => $filter,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        $equipments = Equipment::get();
        $models = Order::distinct()->pluck('model');
        $sourceSchedule = null;

        if ($request->filled('schedule_id')) {
            $sourceSchedule = Schedule::query()
                ->with(['customer:id,name', 'user:id,name'])
                ->findOrFail($request->integer('schedule_id'));
            $this->authorize('view', $sourceSchedule);
        }

        return Inertia::render('app/orders/create-order', [
            'equipments' => $equipments,
            'models' => $models,
            'sourceSchedule' => $sourceSchedule,
            'warrantySourceOrders' => $this->warrantySourceOptions(),
            'activeOrders' => $this->activeOrderOptions(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(OrderRequest $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $data = $request->validated();
        $sourceScheduleId = $data['schedule_id'] ?? null;
        unset($data['schedule_id']);

        Customer::query()->whereKey($data['customer_id'])->firstOrFail();
        $sourceSchedule = null;
        if ($sourceScheduleId) {
            $sourceSchedule = Schedule::query()
                ->whereKey($sourceScheduleId)
                ->where('customer_id', $data['customer_id'])
                ->first();
            abort_unless($sourceSchedule, 404);
            $this->authorize('update', $sourceSchedule);
        }

        if (($data['order_type'] ?? Order::TYPE_EQUIPMENT) === Order::TYPE_EQUIPMENT) {
            Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();
        }
        $tenantId = (int) ($this->currentUser()?->tenant_id ?? 0);
        $data['order_number'] = TenantSequence::next(Order::class, 'order_number', $tenantId);
        $data['barcode'] = Ean13::fromNumber($data['order_number']);
        $data['tracking_token'] = Str::uuid();
        $publicAccessKey = Str::upper(Str::random(8));
        $data['public_access_key'] = $publicAccessKey;
        $data['public_access_key_hash'] = Hash::make($publicAccessKey);
        $data['warranty_days'] = isset($data['warranty_days']) && $data['warranty_days'] !== '' ? max(0, (int) $data['warranty_days']) : null;
        $data = $this->normalizeDeliveryDateForStatus($data);
        $isEquipmentOrder = ($data['order_type'] ?? Order::TYPE_EQUIPMENT) === Order::TYPE_EQUIPMENT;
        $warrantySourceOrder = $isEquipmentOrder ? $this->warrantySourceOrder($data) : null;
        $data['equipment_id'] = $isEquipmentOrder ? ($data['equipment_id'] ?? null) : null;
        $data['customer_equipment_id'] = $isEquipmentOrder ? ($data['customer_equipment_id'] ?? null) : null;
        $data['model'] = $isEquipmentOrder ? ($data['model'] ?? null) : null;
        $data['password'] = $isEquipmentOrder ? ($data['password'] ?? null) : null;
        $data['state_conservation'] = $isEquipmentOrder ? ($data['state_conservation'] ?? null) : null;
        $data['accessories'] = $isEquipmentOrder ? ($data['accessories'] ?? null) : null;
        $data['warranty_days'] = $isEquipmentOrder ? $data['warranty_days'] : null;
        $data['service_type'] = $isEquipmentOrder ? null : ($data['service_type'] ?? null);
        $data['service_details'] = $isEquipmentOrder ? null : ($data['service_details'] ?? null);
        $data['materials_used'] = $isEquipmentOrder ? null : ($data['materials_used'] ?? null);
        $data['defect'] = $isEquipmentOrder
            ? $data['defect']
            : ($data['service_type'] ?: ($data['service_details'] ?: 'Serviço externo'));
        $data['is_warranty_return'] = (bool) $warrantySourceOrder;
        $data['warranty_source_order_id'] = $warrantySourceOrder?->id;
        $budgetValidUntil = $data['budget_valid_until'] ?? null;
        unset($data['status_reason'], $data['status_change_kind'], $data['budget_valid_until'], $data['delivery_forecast_reason']);

        $order = DB::transaction(function () use ($data, $sourceSchedule, $warrantySourceOrder, $budgetValidUntil): Order {
            $order = Order::create($data);
            if ($sourceSchedule) {
                $sourceSchedule->update(['order_id' => $order->id]);
            }

            $this->orderTotalsService->recalculate($order);

            $this->orderStatusService->recordCreation(
                $order,
                OrderActor::userOrSystem($this->currentUser()),
                array_filter([
                    'source' => $sourceSchedule ? 'schedule' : 'manual',
                    'schedule_id' => $sourceSchedule?->id,
                    'warranty_source_order_id' => $warrantySourceOrder?->id,
                ], fn ($value) => $value !== null),
            );

            $this->orderBudgetService->syncFromOrder(
                $order,
                null,
                (int) $order->service_status,
                OrderActor::userOrSystem($this->currentUser()),
                $budgetValidUntil,
            );

            return $order;
        });
        event(new OrderLifecycleCreated($order->id, $this->currentUser()?->id, [
            'status' => (int) $order->service_status,
            'status_label' => OrderStatus::label($order->service_status),
            'customer_id' => $order->customer_id,
            'equipment_id' => $order->equipment_id,
            'is_warranty_return' => (bool) $order->is_warranty_return,
            'warranty_source_order_number' => $warrantySourceOrder?->order_number,
        ]));

        $successMessage = 'Ordem cadastrada com sucesso';

        try {
            event(new OrderCreated($order));
        } catch (\Throwable $e) {
            report($e);
            $successMessage = 'Ordem cadastrada com sucesso, mas houve falha ao enviar o e-mail ao cliente.';
        }

        $shouldShowLabelButton = $tenantId > 0
            ? (bool) Other::query()
                ->where('tenant_id', $tenantId)
                ->value('print_label_button_after_order_create')
            : false;

        if ($shouldShowLabelButton && ! $sourceSchedule) {
            return redirect()
                ->route('app.orders.create')
                ->with('success', $successMessage)
                ->with('label_print', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'print_url' => route('app.label-printing.print', [
                        'initialorder' => $order->order_number,
                        'quantity' => 1,
                        'format' => 'thermal',
                    ]),
                ]);
        }

        if ($sourceSchedule) {
            return redirect()
                ->route('app.schedules.show', ['schedule' => $sourceSchedule->id])
                ->with('success', $successMessage.' e vinculada ao agendamento.');
        }

        return redirect()->route('app.orders.index')->with('success', $successMessage);
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order, Request $request)
    {
        $this->authorize('view', $order);

        $order->load([
            'customer',
            'customerEquipment',
            'orderParts',
            'orderItems',
            'orderPayments',
        ]);

        $equipments = Equipment::get();
        $parts = Part::where('type', 'part')->get();

        $technicals = User::whereIn('roles', [User::ROLE_TECHNICIAN, User::ROLE_ADMIN])
            ->where('status', 1)
            ->get();
        $models = Order::distinct()->pluck('model');
        $paymentSummary = $this->buildPaymentSummary($order);
        $order = $this->appendPaymentReminderAvailability($order);
        $warrantySourceOrder = $order->warrantySourceOrder()->first(['id', 'order_number', 'warranty_expires_at']);

        if ($order->customer_equipment_id) {
            // Equipamento cadastrado: identidade real do aparelho, sem depender de heurística por texto.
            $historyQuery = Order::query()
                ->where('customer_equipment_id', $order->customer_equipment_id)
                ->whereKeyNot($order->id)
                ->whereNotNull('delivery_date');
        } else {
            // Fallback para OS antigas/avulsas sem equipamento cadastrado: casamento por cliente+tipo+modelo.
            $historyQuery = Order::query()
                ->where('customer_id', $order->customer_id)
                ->where('equipment_id', $order->equipment_id)
                ->whereKeyNot($order->id)
                ->whereNotNull('delivery_date');

            if (! empty($order->model)) {
                $historyQuery->where('model', $order->model);
            }
        }

        $equipmentHistory = $historyQuery
            ->orderByDesc('delivery_date')
            ->limit(5)
            ->get([
                'id',
                'order_number',
                'defect',
                'service_status',
                'delivery_date',
                'warranty_days',
                'warranty_expires_at',
                'service_cost',
            ]);

        $activeWarrantyOrder = $equipmentHistory
            ->first(fn (Order $historyOrder) => $historyOrder->warranty_expires_at && $historyOrder->warranty_expires_at->isFuture());

        return Inertia::render('app/orders/edit-order', [
            'order' => $order,
            'orderparts' => $order->orderParts,
            'orderPayments' => $order->orderPayments,
            'paymentSummary' => $paymentSummary,
            'technicals' => $technicals,
            'equipments' => $equipments,
            'parts' => $parts,
            'models' => $models,
            'equipmentHistory' => [
                'total_previous_orders' => $equipmentHistory->count(),
                'has_recurrence' => $equipmentHistory->isNotEmpty(),
                'same_defect_count' => $equipmentHistory->filter(function (Order $historyOrder) use ($order) {
                    return strcasecmp(trim((string) $historyOrder->defect), trim((string) $order->defect)) === 0;
                })->count(),
                'active_warranty' => $activeWarrantyOrder ? [
                    'order_number' => $activeWarrantyOrder->order_number,
                    'warranty_expires_at' => $activeWarrantyOrder->warranty_expires_at?->toIso8601String(),
                ] : null,
                'is_warranty_return' => (bool) $order->is_warranty_return,
                'warranty_source_order' => $warrantySourceOrder ? [
                    'order_number' => $warrantySourceOrder->order_number,
                    'warranty_expires_at' => $warrantySourceOrder->warranty_expires_at?->toIso8601String(),
                ] : null,
                'history' => $equipmentHistory,
            ],
            'warrantySourceOrders' => $this->warrantySourceOptions($order->warranty_source_order_id, $order->id),
            'publicAccessKey' => $order->public_access_key,
            'budgets' => $order->budgets()->with('items')->get()->reverse()->values(),
            'messages' => $order->messages()->limit(50)->get([
                'id', 'order_budget_id', 'channel', 'recipient', 'template', 'status',
                'sent_at', 'delivered_at', 'read_at', 'failed_at', 'error_message', 'created_at',
            ]),
            'page' => $request->page,
            'search' => $request->search,
            'status' => $request->status,
            'filter' => $request->filter,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order, Request $request)
    {
        $this->authorize('view', $order);

        return redirect()->route('app.orders.show', [
            'order' => $order->id,
            'page' => $request->page,
            'search' => $request->search,
            'status' => $request->status,
            'filter' => $request->filter,
            'open_payments' => $request->get('open_payments'),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(OrderRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $data = $request->all();
        $request->validated();
        if ($order->is_warranty_return) {
            $data['is_warranty_return'] = true;
            $data['warranty_source_order_id'] = $order->warranty_source_order_id;
        }
        Customer::query()->whereKey($data['customer_id'])->firstOrFail();
        $isEquipmentOrder = ($data['order_type'] ?? Order::TYPE_EQUIPMENT) === Order::TYPE_EQUIPMENT;
        if ($isEquipmentOrder) {
            Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();
        }
        if (! empty($data['user_id'])) {
            User::query()
                ->whereKey($data['user_id'])
                ->whereIn('roles', [User::ROLE_TECHNICIAN, User::ROLE_ADMIN])
                ->where('status', 1)
                ->firstOrFail();
        }
        $data['budget_value'] = $this->normalizeMoneyValue($data['budget_value'] ?? 0);
        $data['service_value'] = $this->normalizeMoneyValue($data['service_value'] ?? 0);
        // parts_value e service_cost enviados pelo navegador são ignorados: o servidor recalcula
        // (OrderTotalsService). Desconto/acréscimo ausentes mantêm o valor já gravado.
        $data['discount_amount'] = $request->has('discount_amount')
            ? $this->normalizeMoneyValue($data['discount_amount'])
            : (string) ($order->discount_amount ?? '0.00');
        $data['surcharge_amount'] = $request->has('surcharge_amount')
            ? $this->normalizeMoneyValue($data['surcharge_amount'])
            : (string) ($order->surcharge_amount ?? '0.00');
        $nextParts = $this->requestedPartQuantities($data);
        $data['manual_parts_value'] = $this->resolveManualPartsValue($request, $data, $order, $nextParts);
        // Custo avulso: vazio = desconhecido (null), 0 = custo zero; ausente na requisição mantém o gravado.
        $data['manual_parts_cost'] = $request->has('manual_parts_cost')
            ? ($data['manual_parts_cost'] === null || $data['manual_parts_cost'] === '' ? null : $this->normalizeMoneyValue($data['manual_parts_cost']))
            : $order->manual_parts_cost;
        $warrantyDays = isset($data['warranty_days']) && $data['warranty_days'] !== '' ? max(0, (int) $data['warranty_days']) : null;
        $submittedDeliveryDate = ! empty($data['delivery_date']) ? Carbon::parse($data['delivery_date']) : null;
        $warrantySourceOrder = $isEquipmentOrder ? $this->warrantySourceOrder($data, $order) : null;
        $oldStatus = (int) $order->service_status;
        $newStatus = (int) $data['service_status'];
        $statusReason = trim((string) ($data['status_reason'] ?? '')) ?: null;
        $statusChangeKind = $data['status_change_kind'] ?? null;
        // Valida a transição antes de gravar qualquer campo, para não salvar a OS pela metade.
        $this->orderStatusService->assertTransition($oldStatus, $newStatus, $statusReason, $statusChangeKind);
        $actor = OrderActor::userOrSystem($this->currentUser());
        $previousTechnicianId = $order->user_id ? (int) $order->user_id : null;
        $newTechnicianId = ! empty($data['user_id']) ? (int) $data['user_id'] : null;
        $forecastReason = trim((string) ($data['delivery_forecast_reason'] ?? '')) ?: null;
        $budgetValidUntil = $request->has('budget_valid_until') ? ($data['budget_valid_until'] ?: null) : false;
        $successMessage = 'Ordem atualizada com sucesso';

        $changes = [];

        // OS, técnico, prazo, status, peças/estoque/movimentos, itens, totais, recebível e
        // comissão em uma única transação: qualquer falha desfaz tudo.
        DB::transaction(function () use ($order, $data, $isEquipmentOrder, $warrantyDays, $warrantySourceOrder, $oldStatus, $newStatus, $statusReason, $statusChangeKind, $actor, $previousTechnicianId, $newTechnicianId, $nextParts, $submittedDeliveryDate, $forecastReason, $budgetValidUntil, &$changes): void {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $order->update([
                'order_type' => $data['order_type'] ?? Order::TYPE_EQUIPMENT,
                'customer_id' => $data['customer_id'],
                'equipment_id' => $isEquipmentOrder ? $data['equipment_id'] : null, // equipamento
                'customer_equipment_id' => $isEquipmentOrder ? ($data['customer_equipment_id'] ?? null) : null,
                'model' => $isEquipmentOrder ? $data['model'] : null,
                'password' => $isEquipmentOrder ? $data['password'] : null,
                'defect' => $isEquipmentOrder ? $data['defect'] : ($data['service_type'] ?: ($data['service_details'] ?: 'Serviço externo')),
                'service_type' => $isEquipmentOrder ? null : ($data['service_type'] ?? null),
                'service_details' => $isEquipmentOrder ? null : ($data['service_details'] ?? null),
                'materials_used' => $isEquipmentOrder ? null : ($data['materials_used'] ?? null),
                'state_conservation' => $isEquipmentOrder ? $data['state_conservation'] : null, // estado de conservação
                'accessories' => $isEquipmentOrder ? $data['accessories'] : null,
                'budget_description' => $data['budget_description'] ?? null,
                'budget_value' => $data['budget_value'] ?? 0,
                'budget_link' => $data['budget_link'] ?? null,
                'services_performed' => $data['services_performed'], // servicos executados
                'service_value' => $data['service_value'] ?? 0,
                'manual_parts_value' => $data['manual_parts_value'],
                'manual_parts_cost' => $data['manual_parts_cost'],
                'discount_amount' => $data['discount_amount'],
                'surcharge_amount' => $data['surcharge_amount'],
                'warranty_days' => $isEquipmentOrder ? $warrantyDays : null,
                'is_warranty_return' => (bool) $warrantySourceOrder,
                'warranty_source_order_id' => $warrantySourceOrder?->id,
                'service_status' => $oldStatus,
                'observations' => $data['observations'],
            ]);
            $changes = collect($order->getChanges())
                ->except(['updated_at'])
                ->toArray();

            // Técnico responsável: só pelo serviço de atribuição, que mantém o histórico temporal.
            $this->orderTechnicianAssignmentService->assign($order, $newTechnicianId, $actor);
            if ($previousTechnicianId !== $newTechnicianId) {
                $changes['user_id'] = $newTechnicianId;
            }

            // Prazo: renegociação vira evento; o prazo original nunca é sobrescrito.
            $this->orderDeadlineService->changeForecast($order, $data['delivery_forecast'] ?? null, $actor, $forecastReason);

            if ($newStatus !== $oldStatus) {
                // Entrar em "Entregue" grava a data da entrega; sair preserva a entrega anterior.
                $this->orderStatusService->transition($order, $newStatus, $actor, $statusReason, $statusChangeKind, [], $submittedDeliveryDate);
            } elseif ($newStatus === OrderStatus::DELIVERED && $submittedDeliveryDate) {
                $this->orderDeadlineService->correctDeliveryDate($order, $submittedDeliveryDate, $actor);
            }

            $addedPartIds = [];
            if ($nextParts !== null) {
                $addedPartIds = $this->orderPartsService->sync($order, $nextParts, $this->currentUser()?->id)['added'];
            }

            $this->orderItemSyncService->sync($order, $addedPartIds);
            $this->orderTotalsService->recalculate($order);

            // Orçamento versionado: rascunho, envio (status 3), aprovação/recusa (4/5) e novas versões.
            $this->orderBudgetService->syncFromOrder(
                $order,
                $oldStatus,
                $newStatus,
                $actor,
                $budgetValidUntil,
                $statusReason,
            );

            // Garantia conta da data real da entrega (preservada mesmo após reabertura).
            $order->forceFill([
                'warranty_expires_at' => $isEquipmentOrder && $order->delivery_date && $warrantyDays
                    ? Carbon::parse($order->delivery_date)->addDays($warrantyDays)
                    : null,
            ])->save();

            $fresh = $order->fresh(['orderPayments']);
            $this->financialReceivableService->syncOrder($fresh);
            $this->technicianCommissionService->syncOrder($fresh);
        });

        if ($newStatus !== $oldStatus) {
            $currentStatus = $newStatus;
            $statusLabel = OrderStatus::label($currentStatus);
            $statusChangeData = [
                'from' => (int) $oldStatus,
                'from_label' => OrderStatus::label($oldStatus),
                'to' => $currentStatus,
                'to_label' => $statusLabel,
                'changes' => $changes,
            ];
            event(new OrderLifecycleStatusChanged(
                $order->id,
                $this->currentUser()?->id,
                $statusChangeData,
                $statusChangeData,
            ));

            try {
                event(new OrderStatusUpdated($order->fresh(['customer', 'tenant']), $statusLabel, $data['observations'] ?? null));
            } catch (\Throwable $exception) {
                report($exception);
                $successMessage = 'Ordem atualizada com sucesso, mas houve falha ao enviar o e-mail de status ao cliente.';
            }
        }

        $order = $order->fresh(['orderPayments']);

        $currentUser = $this->currentUser();
        if (
            $currentUser?->isTechnician()
            && ! is_null($order->user_id)
            && (int) $order->user_id !== (int) $currentUser->id
        ) {
            return redirect()
                ->route('app.orders.index')
                ->with('success', 'Ordem transferida e atualizada com sucesso');
        }

        return redirect()->route('app.orders.show', [
            'order' => $order->id,
            'page' => $request->page,
            'search' => $request->search,
            'status' => $request->status,
            'filter' => $request->filter,
        ])->with('success', $successMessage);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        $this->authorize('delete', $order);

        $hasPayments = $order->orderPayments()->exists();
        $isSafeStatus = in_array((int) $order->service_status, [OrderStatus::OPEN, OrderStatus::CANCELLED], true);

        if (FiscalDocument::hasNativeHistoryFor($order)) {
            return back()->with('error', 'Ordens com nota fiscal emitida pelo sistema não podem ser excluídas, para preservar o histórico fiscal.');
        }

        if ($hasPayments || ! $isSafeStatus) {
            return back()->with(
                'error',
                'Não é possível excluir uma ordem que já teve orçamento gerado, pagamento registrado ou andamento no atendimento. Cancele a ordem em vez de excluí-la.'
            );
        }

        // Estoque devolvido pela camada central (movimento + custo), recebível, comissão e
        // a própria OS na mesma transação: qualquer falha desfaz tudo.
        DB::transaction(function () use ($order): void {
            $this->orderPartsService->returnAll($order, $this->currentUser()?->id);
            $this->financialReceivableService->deleteSource('order', (int) $order->id, (int) $order->tenant_id);
            $this->technicianCommissionService->deleteForOrder($order);
            $order->delete();
        });

        return redirect()->route('app.orders.index')->with('success', 'Ordem excluída com sucesso');
    }

    public function removePart(Request $request)
    {
        $this->authorize('create', Order::class);

        $validatedData = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'part_id' => 'required|integer|exists:parts,id',
        ]);

        $order = Order::find($validatedData['order_id']);
        abort_unless($order, 404);
        $this->authorize('update', $order);

        // Devolve ao estoque, remove só o item dessa peça e recalcula os totais no servidor,
        // tudo na mesma transação (sem usar o preço atual do cadastro).
        $removed = DB::transaction(function () use ($order, $validatedData): bool {
            if (! $this->orderPartsService->remove($order, (int) $validatedData['part_id'], $this->currentUser()?->id)) {
                return false;
            }

            $this->orderItemSyncService->sync($order);
            $this->orderTotalsService->recalculate($order);
            $this->financialReceivableService->syncOrder($order->fresh(['orderPayments']));

            return true;
        });

        if (! $removed) {
            return back()->with('error', 'A peça informada não está vinculada a esta ordem.');
        }

        return redirect()->route('app.orders.show', $order)->with('success', 'Peça removida e estoque devolvido com sucesso.');
    }

    public function storePayment(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_unless($this->financeEnabled(), 403);

        $request->merge([
            'amount' => $this->normalizeMoneyFloat($request->input('amount')),
            'fee_amount' => $this->normalizeMoneyFloat($request->input('fee_amount')),
        ]);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'fee_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:pix,cartao,dinheiro,transferencia,boleto',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = $this->orderPaymentService->register($order, [
            ...$validated,
            'amount' => $this->roundMoney((float) $validated['amount']),
        ]);
        $paymentEventData = [
            'payment_id' => $payment->id,
            'cash_session_id' => $payment->cash_session_id,
            'amount' => (float) $payment->amount,
            'fee_amount' => (float) $payment->fee_amount,
            'net_amount' => (float) $payment->net_amount,
            'payment_method' => $validated['payment_method'],
            'paid_at' => $payment->paid_at?->toDateTimeString(),
        ];
        event(new OrderPaymentRegistered($order->id, $this->currentUser()?->id, $paymentEventData));

        return back()->with('success', 'Pagamento registrado com sucesso.');
    }

    public function destroyPayment(Order $order, OrderPayment $payment): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_unless($this->financeEnabled(), 403);
        abort_unless((int) $payment->order_id === (int) $order->id, 404);

        try {
            $paymentData = $this->orderPaymentService->remove($payment);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        event(new OrderPaymentRemoved($order->id, $this->currentUser()?->id, $paymentData));

        return back()->with('success', 'Pagamento removido com sucesso.');
    }

    public function confirmMobilePayment(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_unless($this->financeEnabled() && $this->canConfirmMobilePayment(), 403);

        $payment = $this->orderPaymentService->confirmMobilePayment($order);

        $paymentEventData = [
            'payment_id' => $payment->id,
            'cash_session_id' => $payment->cash_session_id,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at?->toDateTimeString(),
            'source' => 'mobile_app_confirmation',
        ];
        event(new OrderPaymentRegistered($order->id, $this->currentUser()?->id, $paymentEventData));

        return back()->with('success', 'Pagamento conferido e inserido no caixa.');
    }

    public function paymentsData(Order $order)
    {
        $this->authorize('view', $order);
        abort_unless($this->financeEnabled(), 403);

        $order->load('customer');
        $orderPayments = $order->orderPayments()->latest('paid_at')->get();
        $order->setRelation('orderPayments', $orderPayments);
        $paymentSummary = $this->buildPaymentSummary($order);

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'can_send_payment_reminder' => $this->shouldSendCustomerMailer($order, $order->customer?->email),
            ],
            'orderPayments' => $orderPayments,
            'paymentSummary' => $paymentSummary,
            'pendingMobilePayment' => $order->technician_local_payment_received
                && $order->technician_local_payment_status === OrderPaymentService::MOBILE_PAYMENT_PENDING
                ? [
                    'amount' => $order->technician_local_payment_amount,
                    'payment_method' => $order->technician_local_payment_method,
                    'notes' => $order->technician_local_payment_notes,
                    'received_at' => $order->technician_local_payment_received_at,
                    'user_id' => $order->technician_local_payment_user_id,
                ]
                : null,
        ]);
    }

    public function registerFiscal(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $validated = $request->validate([
            'fiscal_document_number' => 'required|string|max:120',
            'fiscal_document_url' => 'nullable|url|max:500',
            'fiscal_issued_at' => 'nullable|date',
            'fiscal_notes' => 'nullable|string|max:500',
        ]);

        try {
            $document = $this->fiscalDocumentService->registerManualOrder($order, $validated, (int) Auth::id());
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Comprovante fiscal da ordem registrado com sucesso.');
    }

    public function sendPaymentReminder(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_unless($this->financeEnabled(), 403);

        $order->load('customer', 'orderPayments');
        $paymentSummary = $this->buildPaymentSummary($order);

        if ((float) ($paymentSummary['total_order'] ?? 0) <= 0) {
            return back()->with('error', 'Defina os valores financeiros da ordem antes de enviar lembrete.');
        }

        if ((float) ($paymentSummary['remaining'] ?? 0) <= 0) {
            return back()->with('success', 'Esta ordem já está quitada, nenhum lembrete foi enviado.');
        }

        if (empty($order->delivery_date)) {
            return back()->with('error', 'O lembrete só pode ser enviado após a entrega do equipamento.');
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->shouldSendCustomerMailer($order, $customerEmail)) {
            return back()->with('error', 'Envio indisponível: cliente sem e-mail válido ou SMTP do cliente não configurado.');
        }

        $isOverdue = false;
        if (! empty($order->delivery_date)) {
            $isOverdue = Carbon::parse($order->delivery_date)->lt(now()->subDays(7));
        }

        try {
            $this->orderNotificationService->sendPaymentReminder($order, $paymentSummary, $isOverdue);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Falha ao enviar o e-mail de cobrança. Verifique a configuração SMTP e tente novamente.');
        }

        $this->logOrderAction($order, 'payment_reminder_sent', [
            'channel' => 'email',
            'recipient' => $customerEmail,
            'remaining' => (float) ($paymentSummary['remaining'] ?? 0),
            'is_overdue' => $isOverdue,
            'trigger' => 'manual',
        ]);

        return back()->with('success', 'E-mail de cobrança/lembrete enviado com sucesso.');
    }

    public function sendBudgetFollowUp(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $order->load('customer', 'tenant');

        if ((int) $order->service_status !== OrderStatus::BUDGET_GENERATED) {
            return back()->with('error', 'O acompanhamento de orçamento só pode ser enviado quando a ordem estiver com orçamento gerado.');
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->shouldSendCustomerMailer($order, $customerEmail)) {
            return back()->with('error', 'Envio indisponível: cliente sem e-mail válido ou SMTP do cliente não configurado.');
        }

        $daysPending = $this->communicationDaysPending($order);

        try {
            $this->orderNotificationService->sendBudgetFollowUp($order, $daysPending);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Falha ao enviar o acompanhamento do orçamento. Verifique a configuração SMTP e tente novamente.');
        }

        $this->logOrderAction($order, 'budget_follow_up_sent', [
            'channel' => 'email',
            'recipient' => $customerEmail,
            'days_pending' => $daysPending,
            'trigger' => 'manual',
        ]);

        return back()->with('success', 'Acompanhamento de orçamento enviado com sucesso.');
    }

    public function sendCustomerUpdate(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if ((int) $order->service_status === OrderStatus::DELIVERED) {
            return back()->with('error', 'Esta ordem já foi entregue, não é possível enviar uma atualização de andamento.');
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $order->load('customer', 'tenant');
        $customerEmail = trim((string) ($order->customer?->email ?? ''));
        $note = trim($validated['note']);

        $order->update([
            'customer_update_note' => $note,
            'customer_update_note_at' => now(),
        ]);

        if (! $this->shouldSendCustomerMailer($order, $customerEmail)) {
            return back()->with('success', 'Atualização salva e visível no acompanhamento público da ordem. E-mail não enviado: cliente sem e-mail válido ou SMTP não configurado.');
        }

        try {
            event(new OrderStatusUpdated($order->fresh(['customer', 'tenant']), OrderStatus::label((int) $order->service_status), $note));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('success', 'Atualização salva e visível no acompanhamento público da ordem, mas houve falha ao enviar o e-mail.');
        }

        return back()->with('success', 'Atualização enviada ao cliente por e-mail e publicada no acompanhamento da ordem.');
    }

    /**
     * Envia, via WAHA, uma mensagem de WhatsApp já composta pelo frontend (a partir dos
     * templates configurados pelo tenant) para o telefone cadastrado do cliente da OS.
     */
    public function sendWhatsapp(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'template' => ['nullable', 'string', Rule::in(OrderMessageService::WHATSAPP_TEMPLATES)],
        ]);

        $order->loadMissing('customer');
        $phone = $order->customer?->whatsapp;
        $template = $validated['template'] ?? null;
        $recipient = WhatsAppPhone::isValid($phone) ? WhatsAppPhone::normalize($phone) : null;

        try {
            $sent = $this->whatsAppService->sendText((int) $order->tenant_id, $phone, $validated['message']);
        } catch (WhatsAppException $exception) {
            // A mensagem da exceção já é segura para o usuário (sem credenciais do WAHA).
            $this->orderMessageService->recordFailed($order, OrderMessage::CHANNEL_WHATSAPP, $recipient, $template, 'waha', class_basename($exception), $exception->getMessage(), $this->currentUser()?->id);

            return back()->with('error', $exception->getMessage());
        }

        $this->orderMessageService->recordSent($order, OrderMessage::CHANNEL_WHATSAPP, $sent['chat_id'], $template, 'waha', $sent['response'], $this->currentUser()?->id);

        return back()->with('success', 'Mensagem enviada pelo WhatsApp com sucesso.');
    }

    public function markFeedback(Order $order)
    {
        $this->authorize('update', $order);

        $feedbackThreshold = $this->customerFeedbackRequestThreshold();
        $feedbackExpirationThreshold = $this->customerFeedbackExpirationThreshold();

        if ((int) $order->service_status !== OrderStatus::DELIVERED || ! $order->delivery_date) {
            return back()->with('error', 'Esta ordem não está elegível para feedback.');
        }

        $deliveryDate = Carbon::parse($order->delivery_date);
        $isInWindow = $deliveryDate->lte($feedbackThreshold) && $deliveryDate->gt($feedbackExpirationThreshold);

        if (! $isInWindow) {
            return back()->with('error', 'Esta ordem ainda não está elegível para feedback.');
        }

        if ((bool) $order->feedback) {
            return back()->with('success', 'Feedback já foi marcado como realizado.');
        }

        $order->update(['feedback' => 1]);

        return back()->with('success', 'Feedback marcado como realizado.');
    }
}
