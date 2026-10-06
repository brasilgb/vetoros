<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\Schedule;
use App\Models\App\ScheduleImage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class TechnicianScheduleController extends Controller
{
    private const MAX_IMAGES_PER_SCHEDULE = 4;

    private function orderScheduleService($order): ?string
    {
        if (! $order) {
            return null;
        }

        $service = $order->order_type === \App\Models\App\Order::TYPE_EXTERNAL_SERVICE
            ? ($order->service_type ?: $order->defect)
            : $order->defect;

        return mb_substr(trim((string) $service), 0, 500) ?: 'Atendimento da OS #'.$order->order_number;
    }

    private function orderScheduleDetails($order): ?string
    {
        if (! $order) {
            return null;
        }

        $details = collect([
            $order->service_details,
            $order->materials_used ? 'Materiais utilizados: '.$order->materials_used : null,
            $order->budget_description,
            $order->observations,
            $order->services_performed,
        ])->filter(fn ($value) => filled($value))->implode("\n\n");

        return mb_substr($details ?: $this->orderScheduleService($order), 0, 500);
    }

    private function technician(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canUseTechnicianApp(), 403);

        return $user;
    }

    private function schedulesQuery(User $technician)
    {
        return Schedule::query()
            ->with([
                'customer:id,name,email,phone,whatsapp,zipcode,state,city,district,street,number,complement,observations',
                'order:id,customer_id,equipment_id,user_id,order_number,order_type,tracking_token,model,defect,service_type,service_details,materials_used,state_conservation,accessories,budget_description,budget_value,service_status,observations,services_performed,technician_diagnosis,technician_solution,technician_observations,technician_checklist_items,technician_checklist_completed_at,technician_attended_at,technician_local_payment_received,technician_local_payment_status,technician_local_payment_amount,technician_local_payment_method,technician_local_payment_notes,technician_local_payment_received_at,technician_local_payment_user_id,service_cost,delivery_forecast,delivery_date',
                'order.orderPayments:id,order_id,amount,payment_method,paid_at,notes',
                'order.equipment:id,equipment_number,equipment',
                'order.equipment.checklists:id,equipment_id,checklist',
                'user:id,name,email,telephone,whatsapp,avatar',
            ])
            ->where('user_id', $technician->id)
            ->where('send_to_technician', true);
    }

    private function scheduleService(Schedule $schedule): ?string
    {
        if (filled($schedule->service)) {
            return (string) $schedule->service;
        }

        return $this->orderScheduleService($schedule->order);
    }

    private function scheduleDetails(Schedule $schedule): ?string
    {
        if (filled($schedule->details)) {
            return (string) $schedule->details;
        }

        return $this->orderScheduleDetails($schedule->order);
    }

    private function materialUsageSnapshot(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item): bool => (bool) ($item['used'] ?? false) && filled($item['part_id'] ?? null))
            ->groupBy(fn (array $item): int => (int) $item['part_id'])
            ->map(fn ($items): int => $items->sum(fn (array $item): int => max(1, (int) ($item['quantity'] ?? 1))))
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->toArray();
    }

    private function syncMaterialChecklistStock(Schedule $schedule, array $nextItems, int $userId): void
    {
        $previousUsage = $this->materialUsageSnapshot($schedule->normalizedMaterialChecklist());
        $nextUsage = $this->materialUsageSnapshot($nextItems);
        $partIds = array_values(array_unique(array_merge(array_keys($previousUsage), array_keys($nextUsage))));

        if ($partIds === []) {
            return;
        }

        $parts = Part::query()
            ->where('tenant_id', $schedule->tenant_id)
            ->whereIn('id', $partIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($partIds as $partId) {
            $previousQuantity = (int) ($previousUsage[$partId] ?? 0);
            $nextQuantity = (int) ($nextUsage[$partId] ?? 0);
            $quantityDiff = $nextQuantity - $previousQuantity;

            if ($quantityDiff === 0) {
                continue;
            }

            $part = $parts->get($partId);

            if (! $part) {
                throw ValidationException::withMessages([
                    'materials' => 'Uma das peças informadas não foi encontrada.',
                ]);
            }

            if ($quantityDiff > 0) {
                if ((int) $part->quantity < $quantityDiff) {
                    throw ValidationException::withMessages([
                        'materials' => "Estoque insuficiente para {$part->name}.",
                    ]);
                }

                $part->decrement('quantity', $quantityDiff);
                $movementType = PartMovement::TYPE_ORDER_USE;
                $movementQuantity = $quantityDiff;
                $movementReason = 'Uso no agendamento #'.$schedule->schedules_number;
            } else {
                $movementQuantity = abs($quantityDiff);
                $part->increment('quantity', $movementQuantity);
                $movementType = PartMovement::TYPE_RETURN;
                $movementReason = 'Devolução de peça do agendamento #'.$schedule->schedules_number;
            }

            PartMovement::create([
                'part_id' => $part->id,
                'order_id' => null,
                'user_id' => $userId,
                'movement_type' => $movementType,
                'quantity' => $movementQuantity,
                'reason' => $movementReason,
            ]);
        }
    }

    private function scheduleImagesPath(Schedule $schedule): string
    {
        return public_path('storage/schedules/'.$schedule->id);
    }

    private function scheduleImagePayload(ScheduleImage $image): array
    {
        return [
            'id' => $image->id,
            'tenant_id' => $image->tenant_id,
            'schedule_id' => $image->schedule_id,
            'user_id' => $image->user_id,
            'filename' => $image->filename,
            'created_at' => $image->created_at,
            'updated_at' => $image->updated_at,
        ];
    }

    private function deleteScheduleImageFile(Schedule $schedule, ScheduleImage $image): void
    {
        $path = $this->scheduleImagesPath($schedule).DIRECTORY_SEPARATOR.$image->filename;

        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function index(Request $request)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:today,tomorrow,week,pending,overdue,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->schedulesQuery($technician)
            ->when(($data['period'] ?? null) === 'today', fn ($query) => $query->whereDate('schedules', today()))
            ->when(($data['period'] ?? null) === 'tomorrow', fn ($query) => $query->whereDate('schedules', today()->addDay()))
            ->when(($data['period'] ?? null) === 'week', fn ($query) => $query->whereBetween('schedules', [now()->startOfDay(), now()->addDays(7)->endOfDay()]))
            ->when(($data['period'] ?? null) === 'pending', fn ($query) => $query->whereIn('status', [1, 2]))
            ->when(($data['period'] ?? null) === 'overdue', fn ($query) => $query->whereIn('status', [1, 2])->where('schedules', '<', now()->startOfDay()))
            ->when(($data['period'] ?? null) === 'completed', fn ($query) => $query->where('status', 3))
            ->orderBy('schedules')
            ->orderBy('id');

        $tenant = Tenant::query()->find($technician->tenant_id);

        $schedules = $query
            ->withCount('images')
            ->paginate((int) ($data['per_page'] ?? 50))
            ->through(fn (Schedule $schedule) => $this->schedulePayload($schedule, $tenant));

        return response()->json([
            'success' => true,
            'result' => $schedules,
        ]);
    }

    public function dashboard(Request $request)
    {
        $technician = $this->technician($request);
        $baseQuery = $this->schedulesQuery($technician);
        $tenant = Tenant::query()->find($technician->tenant_id);

        $nextSchedule = (clone $baseQuery)
            ->withCount('images')
            ->where('status', 1)
            ->where('schedules', '>=', now()->startOfDay())
            ->orderBy('schedules')
            ->orderBy('id')
            ->first();

        $currentSchedule = (clone $baseQuery)
            ->withCount('images')
            ->where('status', 2)
            ->orderBy('schedules')
            ->orderBy('id')
            ->first();

        return response()->json([
            'success' => true,
            'result' => [
                'summary' => [
                    'today' => (clone $baseQuery)->whereDate('schedules', today())->count(),
                    'pending' => (clone $baseQuery)->whereIn('status', [1, 2])->count(),
                    'in_progress' => (clone $baseQuery)->where('status', 2)->count(),
                    'overdue' => (clone $baseQuery)->whereIn('status', [1, 2])->where('schedules', '<', now()->startOfDay())->count(),
                    'completed' => (clone $baseQuery)->where('status', 3)->count(),
                ],
                'current_schedule' => $currentSchedule ? $this->schedulePayload($currentSchedule, $tenant) : null,
                'next_schedule' => $nextSchedule ? $this->schedulePayload($nextSchedule, $tenant) : null,
            ],
        ]);
    }

    public function show(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $schedule = $this->schedulesQuery($technician)
            ->withCount('images')
            ->whereKey($schedule->id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'result' => $this->schedulePayload($schedule, Tenant::query()->find($technician->tenant_id)),
        ]);
    }

    public function materials(Request $request)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $parts = Part::query()
            ->where('tenant_id', $technician->tenant_id)
            ->where('type', 'part')
            ->where('status', true)
            ->when(filled($data['search'] ?? null), function ($query) use ($data) {
                $search = $data['search'];

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('part_number', 'like', '%'.$search.'%')
                        ->orWhere('reference_number', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit((int) ($data['limit'] ?? 50))
            ->get(['id', 'name', 'part_number', 'reference_number', 'quantity', 'location']);

        return response()->json([
            'success' => true,
            'result' => $parts,
        ]);
    }

    public function updateStatus(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'status' => ['required', 'integer', 'in:1,2,3'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        if ((int) $data['status'] === 1) {
            abort_if($schedule->check_in_at, 422, 'Não é possível reverter um atendimento com check-in registrado.');
        }

        if ((int) $data['status'] === 3) {
            abort_unless($schedule->check_in_at, 422, 'Registre o check-in antes de finalizar o atendimento.');
        }

        $schedule->update([
            'status' => $data['status'],
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->schedulePayload($schedule->refresh()->loadMissing([
                'customer',
                'order',
                'order.equipment',
                'user',
            ])),
        ]);
    }

    public function checkIn(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        abort_if((int) $schedule->status === 3 || $schedule->check_out_at, 422, 'Atendimento ja finalizado.');
        abort_if($schedule->check_in_at, 422, 'Check-in ja registrado para este atendimento.');

        $schedule->update([
            'status' => 2,
            'check_in_at' => now(),
            'check_in_latitude' => $data['latitude'] ?? null,
            'check_in_longitude' => $data['longitude'] ?? null,
            'check_in_observations' => $data['observations'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function checkOut(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        abort_if((int) $schedule->status === 3 || $schedule->check_out_at, 422, 'Atendimento ja finalizado.');
        abort_unless($schedule->check_in_at, 422, 'Registre o check-in antes do check-out.');
        $checklistItems = $schedule->technicianChecklistItems();

        if ($checklistItems !== []) {
            $completedItems = $schedule->technician_checklist_items
                ?? $schedule->order?->technician_checklist_items
                ?? [];

            abort_unless(
                empty(array_diff($checklistItems, $completedItems)),
                422,
                'Conclua e salve o checklist antes de finalizar o atendimento.'
            );
        }

        abort_unless(
            filled($schedule->technician_diagnosis) && filled($schedule->technician_solution),
            422,
            'Salve o diagnostico e a solucao antes de finalizar o atendimento.'
        );

        abort_unless(
            $schedule->service_closure_status === 'priced' && (float) $schedule->service_closure_amount > 0,
            422,
            'Solicite o fechamento e aguarde a empresa definir o valor antes do check-out.'
        );

        $schedule->update([
            'status' => 3,
            'check_out_at' => now(),
            'check_out_latitude' => $data['latitude'] ?? null,
            'check_out_longitude' => $data['longitude'] ?? null,
            'check_out_observations' => $data['observations'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function updateReport(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'technician_diagnosis' => ['nullable', 'string', 'max:2000'],
            'technician_solution' => ['nullable', 'string', 'max:2000'],
            'technician_observations' => ['nullable', 'string', 'max:2000'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        $schedule->update([
            'technician_diagnosis' => $data['technician_diagnosis'] ?? null,
            'technician_solution' => $data['technician_solution'] ?? null,
            'technician_observations' => $data['technician_observations'] ?? null,
            'technician_report_updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function updateChecklist(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*' => ['string', 'max:500'],
            'material_checklist' => ['sometimes', 'array'],
            'materials' => ['sometimes', 'array'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        $materialChecklist = $data['material_checklist'] ?? $data['materials'] ?? null;

        if (is_array($materialChecklist)) {
            $nextMaterialChecklist = Schedule::normalizeMaterialChecklist($materialChecklist);

            DB::transaction(function () use ($schedule, $nextMaterialChecklist, $technician): void {
                $lockedSchedule = Schedule::query()
                    ->whereKey($schedule->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->syncMaterialChecklistStock($lockedSchedule, $nextMaterialChecklist, $technician->id);

                $lockedSchedule->update([
                    'material_checklist' => $nextMaterialChecklist,
                ]);
            });
        }

        if (! array_key_exists('items', $data)) {
            return response()->json([
                'success' => true,
                'result' => $this->freshSchedulePayload($schedule),
            ]);
        }

        $availableItems = $schedule->technicianChecklistItems();

        $items = collect($data['items'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->when($availableItems !== [], fn ($items) => $items->intersect($availableItems))
            ->values()
            ->all();

        $schedule->update([
            'technician_checklist_items' => $items,
            'technician_checklist_completed_at' => $availableItems !== [] && empty(array_diff($availableItems, $items))
                ? now()
                : null,
        ]);

        if ($schedule->order && $schedule->technician_checklist === null) {
            $schedule->order->update([
                'technician_checklist_items' => $items,
                'technician_checklist_completed_at' => $items !== [] ? now() : null,
            ]);
        }

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function recordPayment(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid' => ['nullable', 'boolean'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        abort_unless($schedule->service_closure_status === 'priced', 422, 'Aguarde a empresa definir o valor do atendimento.');
        abort_unless(
            abs((float) $data['amount'] - (float) $schedule->service_closure_amount) < 0.01,
            422,
            'O pagamento deve usar o valor definido pela empresa.'
        );

        $schedule->update([
            'local_payment_received' => (bool) ($data['paid'] ?? true),
            'local_payment_amount' => round((float) $data['amount'], 2),
            'local_payment_received_at' => now(),
            'local_payment_user_id' => $technician->id,
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function requestServiceClosure(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        abort_if((int) $schedule->status === 3 || $schedule->check_out_at, 422, 'Atendimento já finalizado.');
        abort_unless($schedule->check_in_at, 422, 'Registre o check-in antes de solicitar o fechamento.');

        abort_unless(
            filled($schedule->technician_diagnosis) && filled($schedule->technician_solution),
            422,
            'Salve o diagnóstico e a solução antes de solicitar o fechamento.'
        );

        $checklistItems = $schedule->technicianChecklistItems();

        if ($checklistItems !== []) {
            $completedItems = $schedule->technician_checklist_items
                ?? $schedule->order?->technician_checklist_items
                ?? [];

            abort_unless(
                empty(array_diff($checklistItems, $completedItems)),
                422,
                'Conclua e salve o checklist antes de solicitar o fechamento.'
            );
        }

        $schedule->update([
            'service_closure_status' => 'requested',
            'service_closure_requested_at' => now(),
            'service_closure_requested_by' => $technician->id,
            'service_closure_amount' => null,
            'service_closure_priced_at' => null,
            'service_closure_priced_by' => null,
        ]);

        return response()->json([
            'success' => true,
            'result' => $this->freshSchedulePayload($schedule),
        ]);
    }

    public function images(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        $images = ScheduleImage::query()
            ->where('tenant_id', $schedule->tenant_id)
            ->where('schedule_id', $schedule->id)
            ->latest('id')
            ->get()
            ->map(fn (ScheduleImage $image): array => $this->scheduleImagePayload($image))
            ->values();

        return response()->json([
            'success' => true,
            'result' => $images,
        ]);
    }

    public function uploadImage(Request $request, Schedule $schedule)
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'filename' => ['required', 'string'],
        ]);

        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        if (ScheduleImage::query()->where('tenant_id', $schedule->tenant_id)->where('schedule_id', $schedule->id)->count() >= self::MAX_IMAGES_PER_SCHEDULE) {
            throw ValidationException::withMessages([
                'filename' => 'Este atendimento pode ter no máximo '.self::MAX_IMAGES_PER_SCHEDULE.' imagens no total.',
            ]);
        }

        $imageContent = base64_decode($data['filename'], true);

        if ($imageContent === false) {
            throw ValidationException::withMessages([
                'filename' => 'Imagem inválida.',
            ]);
        }

        $storePath = $this->scheduleImagesPath($schedule);

        if (! file_exists($storePath)) {
            mkdir($storePath, 0777, true);
        }

        $filename = time().rand(1, 50).'.png';
        File::put($storePath.DIRECTORY_SEPARATOR.$filename, $imageContent);

        $image = ScheduleImage::create([
            'tenant_id' => $schedule->tenant_id,
            'schedule_id' => $schedule->id,
            'user_id' => $technician->id,
            'filename' => $filename,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Imagem salva com sucesso',
            'result' => $this->scheduleImagePayload($image),
        ]);
    }

    public function deleteImage(Request $request, Schedule $schedule, ScheduleImage $image)
    {
        $technician = $this->technician($request);
        $schedule = $this->schedulesQuery($technician)
            ->whereKey($schedule->id)
            ->firstOrFail();

        abort_unless((int) $image->tenant_id === (int) $schedule->tenant_id && (int) $image->schedule_id === (int) $schedule->id, 404);

        $this->deleteScheduleImageFile($schedule, $image);
        $image->delete();

        return response()->json([
            'success' => true,
            'message' => 'Imagem deletada com sucesso!',
        ]);
    }

    private function freshSchedulePayload(Schedule $schedule, ?Tenant $tenant = null): array
    {
        return $this->schedulePayload($schedule->refresh()->loadMissing([
            'customer',
            'order',
            'order.orderPayments',
            'order.equipment',
            'order.equipment.checklists',
            'user',
        ])->loadCount('images'), $tenant);
    }

    /**
     * @param  Tenant|null  $tenant  Passe o tenant já resolvido quando processar várias schedules na
     *                               mesma requisição (evita buscar o mesmo tenant de novo por linha).
     */
    private function schedulePayload(Schedule $schedule, ?Tenant $tenant = null): array
    {
        $customer = $schedule->customer;
        $order = $schedule->order;
        $equipment = $order?->equipment;
        $address = $customer ? collect([
            $customer->street,
            $customer->number,
            $customer->district,
            $customer->city,
            $customer->state,
        ])->filter(fn ($item) => filled($item))->implode(', ') : null;
        $phoneDigits = $customer ? preg_replace('/\D+/', '', (string) $customer->phone) : '';
        $whatsappDigits = $customer ? preg_replace('/\D+/', '', (string) $customer->whatsapp) : '';
        $whatsappNumber = $whatsappDigits && strlen($whatsappDigits) <= 11 ? '55'.$whatsappDigits : $whatsappDigits;
        $imagesCount = $schedule->images_count ?? ScheduleImage::query()
            ->where('tenant_id', $schedule->tenant_id)
            ->where('schedule_id', $schedule->id)
            ->count();
        $tenant ??= Tenant::query()->find($schedule->tenant_id);
        $officeWhatsapp = preg_replace('/\D+/', '', (string) $tenant?->whatsapp);
        $officeWhatsapp = $officeWhatsapp && strlen($officeWhatsapp) <= 11 ? '55'.$officeWhatsapp : $officeWhatsapp;
        $closurePriced = $schedule->service_closure_status === 'priced' && (float) $schedule->service_closure_amount > 0;
        $checklistItems = $schedule->technicianChecklistItems();
        $completedChecklistItems = $schedule->technician_checklist_items
            ?? $order?->technician_checklist_items
            ?? [];
        $checklistComplete = $checklistItems === [] || empty(array_diff($checklistItems, $completedChecklistItems));
        $reportComplete = filled($schedule->technician_diagnosis) && filled($schedule->technician_solution);

        return [
            'id' => $schedule->id,
            'tenant_id' => $schedule->tenant_id,
            'schedules_number' => $schedule->schedules_number,
            'schedules' => $schedule->schedules,
            'service' => $this->scheduleService($schedule),
            'details' => $this->scheduleDetails($schedule),
            'material_checklist' => $schedule->normalizedMaterialChecklist(),
            'material_checklist_labels' => $schedule->materialChecklistLabels(),
            'technician_checklist' => $schedule->technicianChecklistItems(),
            'technician_checklist_items' => $schedule->technician_checklist_items
                ?? $order?->technician_checklist_items
                ?? [],
            'technician_checklist_completed_at' => $schedule->technician_checklist_completed_at
                ?? $order?->technician_checklist_completed_at,
            'status' => $schedule->status,
            'status_label' => $this->statusLabel((int) $schedule->status),
            'observations' => $schedule->observations,
            'send_to_technician' => (bool) $schedule->send_to_technician,
            'available_actions' => [
                'can_update_status' => (int) $schedule->status !== 3,
                'can_check_in' => (int) $schedule->status !== 3 && ! $schedule->check_in_at,
                'can_check_out' => (int) $schedule->status !== 3
                    && filled($schedule->check_in_at)
                    && ! $schedule->check_out_at
                    && $closurePriced
                    && $checklistComplete
                    && $reportComplete,
                'can_finish' => (int) $schedule->status !== 3 && filled($schedule->check_in_at) && ! $schedule->check_out_at,
                'can_cancel' => false,
                'can_edit_service' => (bool) $order,
                'can_record_local_payment' => $closurePriced,
                'can_upload_images' => $imagesCount < self::MAX_IMAGES_PER_SCHEDULE,
                'remaining_images' => max(0, self::MAX_IMAGES_PER_SCHEDULE - $imagesCount),
            ],
            'service_closure' => [
                'status' => $schedule->service_closure_status,
                'requested_at' => $schedule->service_closure_requested_at,
                'amount' => $schedule->service_closure_amount,
                'priced_at' => $schedule->service_closure_priced_at,
                'office_whatsapp_url' => $officeWhatsapp ? 'https://wa.me/'.$officeWhatsapp : null,
            ],
            'technician_report' => [
                'diagnosis' => $schedule->technician_diagnosis,
                'solution' => $schedule->technician_solution,
                'observations' => $schedule->technician_observations,
                'updated_at' => $schedule->technician_report_updated_at,
            ],
            'local_payment' => [
                'received' => (bool) $schedule->local_payment_received,
                'amount' => $schedule->local_payment_amount,
                'received_at' => $schedule->local_payment_received_at,
                'user_id' => $schedule->local_payment_user_id,
            ],
            'check_in' => [
                'at' => $schedule->check_in_at,
                'latitude' => $schedule->check_in_latitude,
                'longitude' => $schedule->check_in_longitude,
                'observations' => $schedule->check_in_observations,
            ],
            'check_out' => [
                'at' => $schedule->check_out_at,
                'latitude' => $schedule->check_out_latitude,
                'longitude' => $schedule->check_out_longitude,
                'observations' => $schedule->check_out_observations,
            ],
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'whatsapp' => $customer->whatsapp,
                'address' => [
                    'zipcode' => $customer->zipcode,
                    'state' => $customer->state,
                    'city' => $customer->city,
                    'district' => $customer->district,
                    'street' => $customer->street,
                    'number' => $customer->number,
                    'complement' => $customer->complement,
                ],
                'observations' => $customer->observations,
                'quick_actions' => [
                    'phone_url' => $phoneDigits ? 'tel:'.$phoneDigits : null,
                    'whatsapp_url' => $whatsappNumber ? 'https://wa.me/'.$whatsappNumber : null,
                    'maps_url' => $address ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($address) : null,
                ],
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_type' => $order->order_type,
                'order_number' => $order->order_number,
                'tracking_token' => $order->tracking_token,
                'model' => $order->model,
                'defect' => $order->defect,
                'service_type' => $order->service_type,
                'service_details' => $order->service_details,
                'materials_used' => $order->materials_used,
                'state_conservation' => $order->state_conservation,
                'accessories' => $order->accessories,
                'budget_description' => $order->budget_description,
                'budget_value' => $order->budget_value,
                'service_status' => $order->service_status,
                'service_status_label' => OrderStatus::label($order->service_status),
                'observations' => $order->observations,
                'services_performed' => $order->services_performed,
                'technician_diagnosis' => $order->technician_diagnosis,
                'technician_solution' => $order->technician_solution,
                'technician_observations' => $order->technician_observations,
                'technician_checklist_items' => $order->technician_checklist_items ?? [],
                'technician_checklist_completed_at' => $order->technician_checklist_completed_at,
                'technician_attended_at' => $order->technician_attended_at,
                'technician_local_payment_received' => (bool) $order->technician_local_payment_received,
                'technician_local_payment_status' => $order->technician_local_payment_status,
                'technician_local_payment_amount' => $order->technician_local_payment_amount,
                'technician_local_payment_method' => $order->technician_local_payment_method,
                'technician_local_payment_notes' => $order->technician_local_payment_notes,
                'technician_local_payment_received_at' => $order->technician_local_payment_received_at,
                'service_cost' => $order->service_cost,
                'delivery_forecast' => $order->delivery_forecast,
                'delivery_date' => $order->delivery_date,
                'order_payments' => $order->orderPayments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'paid_at' => $payment->paid_at,
                    'notes' => $payment->notes,
                ])->values(),
                'equipment' => $equipment ? [
                    'id' => $equipment->id,
                    'equipment_number' => $equipment->equipment_number,
                    'equipment' => $equipment->equipment,
                    'checklist_items' => $equipment->checklists
                        ->flatMap(fn ($checklist) => collect(explode(',', (string) $checklist->checklist))
                            ->map(fn ($item) => trim($item))
                            ->filter())
                        ->values(),
                ] : null,
            ] : null,
            'technician' => $schedule->user ? [
                'id' => $schedule->user->id,
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'telephone' => $schedule->user->telephone,
                'whatsapp' => $schedule->user->whatsapp,
                'avatar' => $schedule->user->avatar,
                'avatar_url' => $schedule->user->avatar ? asset(ltrim($schedule->user->avatar, '/')) : null,
            ] : null,
            'created_at' => $schedule->created_at,
            'updated_at' => $schedule->updated_at,
        ];
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'Aberta',
            2 => 'Em atendimento',
            3 => 'Fechada',
            default => 'Desconhecido',
        };
    }
}
