<?php

namespace App\Http\Controllers\App;

use App\Exceptions\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleRequest;
use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\Schedule;
use App\Models\App\WhatsappMessage;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\TechnicianPushNotificationService;
use App\Services\WhatsAppService;
use App\Support\TenantSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly CashSessionService $cashSessionService,
    ) {}

    private function currentTenantId(): ?int
    {
        return Auth::user()?->tenant_id ? (int) Auth::user()->tenant_id : null;
    }

    private function scheduleTenantId(array $data, ?Schedule $schedule = null): ?int
    {
        if ($schedule?->tenant_id) {
            return (int) $schedule->tenant_id;
        }

        foreach (['customer_id' => Customer::class, 'order_id' => Order::class, 'user_id' => User::class] as $field => $model) {
            if (empty($data[$field])) {
                continue;
            }

            $tenantId = $model::withoutGlobalScopes()
                ->whereKey($data[$field])
                ->value('tenant_id');

            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        return $this->currentTenantId();
    }

    private function scopeSchedulesQuery($query)
    {
        $user = Auth::user();

        if ($user?->isTechnician()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    private function orderScheduleService(Order $order): string
    {
        $service = $order->order_type === Order::TYPE_EXTERNAL_SERVICE
            ? ($order->service_type ?: $order->defect)
            : $order->defect;

        return mb_substr(trim((string) $service), 0, 500) ?: 'Atendimento da OS #'.$order->order_number;
    }

    private function orderScheduleDetails(Order $order): string
    {
        $details = collect([
            $order->service_details,
            $order->materials_used ? 'Materiais utilizados: '.$order->materials_used : null,
            $order->budget_description,
            $order->observations,
            $order->services_performed,
        ])->filter(fn ($value) => filled($value))->implode("\n\n");

        return mb_substr($details ?: $this->orderScheduleService($order), 0, 500);
    }

    private function scheduleService(Schedule $schedule): ?string
    {
        if (filled($schedule->service)) {
            return (string) $schedule->service;
        }

        return $schedule->order ? $this->orderScheduleService($schedule->order) : null;
    }

    private function scheduleDetails(Schedule $schedule): ?string
    {
        if (filled($schedule->details)) {
            return (string) $schedule->details;
        }

        return $schedule->order ? $this->orderScheduleDetails($schedule->order) : null;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Schedule::class);

        $status = $request->status;
        $search = $request->search;

        $query = $this->scopeSchedulesQuery(Schedule::query())->orderBy('id', 'DESC');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('schedules_number', $search)
                    ->orWhere('service', 'like', '%'.$search.'%')
                    ->orWhere('details', 'like', '%'.$search.'%')
                    ->orWhere('material_checklist', 'like', '%'.$search.'%')
                    ->orWhereHas('order', function ($subQuery) use ($search) {
                        $subQuery->where('defect', 'like', '%'.$search.'%')
                            ->orWhere('service_type', 'like', '%'.$search.'%')
                            ->orWhere('service_details', 'like', '%'.$search.'%')
                            ->orWhere('materials_used', 'like', '%'.$search.'%')
                            ->orWhere('observations', 'like', '%'.$search.'%')
                            ->orWhere('services_performed', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%$search%")
                            ->orWhere('cpfcnpj', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('whatsapp', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('user', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%$search%");
                    });
            });
        }
        $schedules = $query
            ->with([
                'user',
                'customer',
                'images',
                'order.images:id,order_id',
                'order.equipment.checklists:id,equipment_id,checklist',
                'order.orderPayments:id,order_id,amount',
            ])
            ->paginate(\App\Support\Pagination::perPage())
            ->through(fn (Schedule $schedule) => $this->scheduleIndexPayload($schedule))
            ->withQueryString();

        return Inertia::render('app/schedules/index', [
            'schedules' => $schedules,
            'search' => $search,
            'status' => $status,
            'tab' => $request->tab,
            'whats' => WhatsappMessage::first(['technicianschedule']),
        ]);
    }

    private function scheduleIndexPayload(Schedule $schedule): array
    {
        $order = $schedule->order;
        $checklistItems = $schedule->technicianChecklistItems();
        $completedChecklistItems = $schedule->technician_checklist_items
            ?? $order?->technician_checklist_items
            ?? [];
        $checklistCompleted = $checklistItems === [] || empty(array_diff($checklistItems, $completedChecklistItems));
        $scheduleImages = $schedule->images
            ->map(fn ($image) => [
                'id' => $image->id,
                'filename' => $image->filename,
                'url' => asset('storage/schedules/'.$schedule->id.'/'.$image->filename),
                'created_at' => $image->created_at,
            ])
            ->values();

        return [
            ...$schedule->toArray(),
            'service' => $this->scheduleService($schedule),
            'details' => $this->scheduleDetails($schedule),
            'material_checklist' => $schedule->normalizedMaterialChecklist(),
            'material_checklist_labels' => $schedule->materialChecklistLabels(),
            'customer' => $schedule->customer,
            'user' => $schedule->user,
            'order' => $order,
            'images' => $scheduleImages,
            'mobile_summary' => [
                'sent_to_technician' => (bool) $schedule->send_to_technician,
                'has_check_in' => filled($schedule->check_in_at),
                'has_check_out' => filled($schedule->check_out_at),
                'has_report' => filled($schedule->technician_diagnosis) && filled($schedule->technician_solution),
                'technician_report' => [
                    'diagnosis' => $schedule->technician_diagnosis,
                    'solution' => $schedule->technician_solution,
                    'observations' => $schedule->technician_observations,
                    'updated_at' => $schedule->technician_report_updated_at,
                ],
                'has_checklist' => $checklistItems !== [],
                'checklist_completed' => $checklistCompleted,
                'checklist_items' => $checklistItems,
                'checklist_completed_items' => $completedChecklistItems,
                'images_count' => $scheduleImages->count(),
                'images' => $scheduleImages,
                'local_payment_received' => (bool) $schedule->local_payment_received,
                'local_payment_amount' => $schedule->local_payment_amount,
                'local_payment_received_at' => $schedule->local_payment_received_at,
                'local_payment_cash_session_id' => $schedule->local_payment_cash_session_id,
                'local_payment_cash_registered_at' => $schedule->local_payment_cash_registered_at,
                'local_payment_registered_in_cashier' => filled($schedule->local_payment_cash_session_id),
                'can_register_local_payment_cashier' => (bool) $schedule->local_payment_received && blank($schedule->local_payment_cash_session_id),
                'payments_count' => $schedule->local_payment_received ? 1 : 0,
                'service_closure_status' => $schedule->service_closure_status,
                'service_closure_requested_at' => $schedule->service_closure_requested_at,
                'service_closure_amount' => $schedule->service_closure_amount,
                'service_closure_priced_at' => $schedule->service_closure_priced_at,
            ],
        ];
    }

    public function defineServiceClosurePrice(Request $request, Schedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($schedule->service_closure_status !== 'requested') {
            return back()->with('error', 'O técnico ainda não solicitou o fechamento deste atendimento.');
        }

        $schedule->update([
            'service_closure_status' => 'priced',
            'service_closure_amount' => round((float) $data['amount'], 2),
            'service_closure_priced_at' => now(),
            'service_closure_priced_by' => Auth::id(),
        ]);

        return back()->with('success', 'Valor do atendimento definido e liberado para o técnico.');
    }

    public function sendWhatsapp(Request $request, Schedule $schedule, WhatsAppService $whatsAppService): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $schedule->loadMissing('user');

        try {
            $whatsAppService->sendText((int) $schedule->tenant_id, $schedule->user?->whatsapp, $validated['message']);
        } catch (WhatsAppException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Mensagem encaminhada ao WhatsApp. Acompanhe o status de entrega.');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Schedule::class);

        $customers = Customer::get();
        $parts = Part::query()
            ->where('type', 'part')
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'part_number', 'reference_number', 'quantity', 'location']);
        $enableTechnicianScheduleNotifications = Other::technicianScheduleNotificationsEnabled($this->currentTenantId());
        $technicals = User::whereIn('roles', [User::ROLE_TECHNICIAN, User::ROLE_ADMIN])
            ->where('status', 1)
            ->get();

        return Inertia::render('app/schedules/create-schedule', [
            'customers' => $customers,
            'parts' => $parts,
            'technicals' => $technicals,
            'enableTechnicianScheduleNotifications' => $enableTechnicianScheduleNotifications,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ScheduleRequest $request): RedirectResponse
    {
        $this->authorize('create', Schedule::class);

        $data = $request->validated();
        Customer::query()->whereKey($data['customer_id'])->firstOrFail();
        $order = null;
        if (! empty($data['order_id'])) {
            $order = Order::query()
                ->whereKey($data['order_id'])
                ->where('customer_id', $data['customer_id'])
                ->first();
            abort_unless($order, 404);
        }
        User::query()->whereKey($data['user_id'])->firstOrFail();
        if ($order && blank($data['service'] ?? null)) {
            $data['service'] = $this->orderScheduleService($order);
            $data['details'] = $data['details'] ?? $this->orderScheduleDetails($order);
        }
        if (! Other::technicianScheduleNotificationsEnabled($this->scheduleTenantId($data))) {
            $data['send_to_technician'] = false;
        }
        $data['schedules_number'] = TenantSequence::next(Schedule::class, 'schedules_number');
        $schedule = Schedule::create($data);
        $this->notifyTechnicianIfNeeded($schedule);

        return redirect()->route('app.schedules.index')->with('success', 'Agenda cadastrada com sucesso');
    }

    /**
     * Display the specified resource.
     */
    public function show(Schedule $schedule, Request $request)
    {
        $this->authorize('view', $schedule);

        $schedule->load([
            'user',
            'customer',
            'images',
            'order.images',
            'order.equipment.checklists',
            'order.orderPayments',
        ]);

        $customers = Customer::get();
        $parts = Part::query()
            ->where('type', 'part')
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'part_number', 'reference_number', 'quantity', 'location']);
        $enableTechnicianScheduleNotifications = Other::technicianScheduleNotificationsEnabled($this->currentTenantId());
        $technicals = User::whereIn('roles', [User::ROLE_TECHNICIAN, User::ROLE_ADMIN])
            ->where('status', 1)
            ->get();

        return Inertia::render('app/schedules/edit-schedule', [
            'schedule' => $this->scheduleIndexPayload($schedule),
            'customers' => $customers,
            'parts' => $parts,
            'technicals' => $technicals,
            'enableTechnicianScheduleNotifications' => $enableTechnicianScheduleNotifications,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Schedule $schedule, Request $request)
    {
        $this->authorize('view', $schedule);

        return redirect()->route('app.schedules.show', [
            'schedule' => $schedule->id,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $data = $request->validated();
        Customer::query()->whereKey($data['customer_id'])->firstOrFail();
        $order = null;
        if (! empty($data['order_id'])) {
            $order = Order::query()
                ->whereKey($data['order_id'])
                ->where('customer_id', $data['customer_id'])
                ->first();
            abort_unless($order, 404);
        }
        User::query()->whereKey($data['user_id'])->firstOrFail();
        if ($order && blank($data['service'] ?? null)) {
            $data['service'] = $this->orderScheduleService($order);
            $data['details'] = $data['details'] ?? $this->orderScheduleDetails($order);
        }
        if (! Other::technicianScheduleNotificationsEnabled($this->scheduleTenantId($data, $schedule))) {
            $data['send_to_technician'] = false;
        }
        $wasSentToTechnician = (bool) $schedule->send_to_technician;
        $schedule->update($data);

        if (! $wasSentToTechnician || $schedule->wasChanged(['user_id', 'schedules', 'order_id', 'customer_id'])) {
            $this->notifyTechnicianIfNeeded($schedule);
        }

        return redirect()->route('app.schedules.show', ['schedule' => $schedule->id])->with('success', 'Agenda editada com sucesso');
    }

    public function registerLocalPaymentCashier(Schedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $schedule->refresh();

        if (! $schedule->local_payment_received || (float) $schedule->local_payment_amount <= 0) {
            return back()->with('error', 'Este agendamento não possui pagamento local para inserir no caixa.');
        }

        if ($schedule->local_payment_cash_session_id) {
            return back()->with('error', 'Este pagamento local já foi inserido no caixa.');
        }

        $cashSession = CashSession::query()
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (! $cashSession) {
            return back()->with('error', 'Abra o caixa antes de registrar este pagamento.');
        }

        DB::transaction(function () use ($schedule, $cashSession): void {
            $this->cashSessionService->registerEntry($cashSession, [
                'amount' => (float) $schedule->local_payment_amount,
                'description' => 'Atendimento do agendamento #'.$schedule->schedules_number,
                'source_type' => 'schedule',
                'source_id' => $schedule->id,
            ], (int) Auth::id());

            $schedule->update([
                'local_payment_cash_session_id' => $cashSession->id,
                'local_payment_cash_registered_at' => now(),
            ]);
        });

        return back()->with('success', 'Pagamento do atendimento inserido no caixa.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Schedule $schedule)
    {
        $this->authorize('delete', $schedule);

        $schedule->loadMissing('order.orderPayments');

        $order = $schedule->order;
        if (
            $schedule->local_payment_received
            || $schedule->check_in_at
            || $schedule->check_out_at
            || ($order && (
                $order->orderPayments->isNotEmpty()
                || $order->technician_local_payment_received
                || $order->technician_attended_at
            ))
        ) {
            return redirect()->route('app.schedules.index')->with(
                'error',
                'Não é possível excluir este agendamento porque ele possui atendimento técnico ou pagamento registrado.'
            );
        }

        $schedule->delete();

        return redirect()->route('app.schedules.index')->with('success', 'Agenda excluida com sucesso');
    }

    private function notifyTechnicianIfNeeded(Schedule $schedule): void
    {
        if (! $schedule->send_to_technician) {
            return;
        }

        Log::info('Disparando notificação de agendamento para técnico responsável.', [
            'schedule_id' => $schedule->id,
            'sender_user_id' => Auth::id(),
            'recipient_user_id' => $schedule->user_id,
            'tenant_id' => $schedule->tenant_id,
        ]);

        app(TechnicianPushNotificationService::class)->notifyScheduleSent($schedule->fresh(['customer']));
    }
}
