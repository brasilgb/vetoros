<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\MaintenanceContract;
use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MaintenanceContractService;
use App\Services\OrderEventRecorder;
use App\Services\OrderStatusService;
use App\Services\OrderTechnicianAssignmentService;
use App\Support\OrderActor;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class OrderEventTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    // ---------------------------------------------------------------- status

    public function test_creation_records_order_created_event_and_initial_assignment(): void
    {
        $technician = $this->technician();

        $this->post(route('app.orders.store'), [
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Notebook',
            'defect' => 'Não liga',
            'password' => 'segredo123',
            'service_status' => OrderStatus::OPEN,
            'user_id' => $technician->id,
            'delivery_forecast' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('order_events', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_ORDER_CREATED,
            'from_status' => null,
            'to_status' => OrderStatus::OPEN,
            'transition_kind' => OrderStatus::KIND_INITIAL,
            'actor_type' => OrderActor::USER,
            'actor_id' => $this->user->id,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_TECHNICIAN_ASSIGNED,
            'technician_id' => $technician->id,
            'previous_technician_id' => null,
        ]);
        $this->assertDatabaseHas('order_technician_assignments', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'technician_id' => $technician->id,
            'assigned_by_type' => OrderActor::USER,
            'assigned_by' => $this->user->id,
            'unassigned_at' => null,
        ]);
        // Histórico legado continua existindo.
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'status' => OrderStatus::OPEN]);
        // Nada sensível nos eventos.
        $this->assertStringNotContainsString('segredo123', (string) OrderEvent::query()->pluck('metadata')->toJson());
        $this->assertSame(2, OrderEvent::query()->where('order_id', $order->id)->count());
    }

    public function test_valid_transition_records_from_to_actor_and_tenant(): void
    {
        $order = $this->order(OrderStatus::OPEN);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'service_status' => OrderStatus::IN_DIAGNOSIS,
        ]))->assertSessionHasNoErrors();

        $event = OrderEvent::query()->where('order_id', $order->id)->sole();

        $this->assertSame(OrderEvent::TYPE_STATUS_CHANGED, $event->event_type);
        $this->assertSame(OrderStatus::OPEN, $event->from_status);
        $this->assertSame(OrderStatus::IN_DIAGNOSIS, $event->to_status);
        $this->assertSame(OrderStatus::KIND_FORWARD, $event->transition_kind);
        $this->assertSame(OrderActor::USER, $event->actor_type);
        $this->assertSame($this->user->id, (int) $event->actor_id);
        $this->assertSame($this->tenant->id, (int) $event->tenant_id);
        $this->assertNull($event->reason);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_invalid_transition_is_rejected_without_saving_anything(): void
    {
        $order = $this->order(OrderStatus::DELIVERED, ['model' => 'Original']);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'model' => 'Alterado',
            'service_status' => OrderStatus::OPEN,
            'status_reason' => 'tentativa sem declarar reabertura',
        ]))->assertSessionHasErrors('service_status');

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->service_status);
        $this->assertSame('Original', $order->model);
        $this->assertSame(0, OrderEvent::query()->where('order_id', $order->id)->count());
    }

    public function test_cancelled_order_can_never_be_delivered(): void
    {
        $order = $this->order(OrderStatus::CANCELLED);

        $this->expectException(ValidationException::class);

        app(OrderStatusService::class)->transition($order, OrderStatus::DELIVERED, OrderActor::user($this->user), 'x', OrderStatus::KIND_REOPEN);
    }

    public function test_waiting_for_part_flow_needs_no_reason(): void
    {
        $order = $this->order(OrderStatus::REPAIR_IN_PROGRESS);
        $service = app(OrderStatusService::class);

        $service->transition($order, OrderStatus::AWAITING_PART, OrderActor::user($this->user));
        $service->transition($order, OrderStatus::REPAIR_IN_PROGRESS, OrderActor::user($this->user));

        $this->assertSame(
            [[OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::AWAITING_PART], [OrderStatus::AWAITING_PART, OrderStatus::REPAIR_IN_PROGRESS]],
            OrderEvent::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OrderEvent $e) => [$e->from_status, $e->to_status])->all()
        );
    }

    public function test_customer_actor_on_public_budget_approval(): void
    {
        $order = $this->order(OrderStatus::BUDGET_GENERATED);
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_events', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_STATUS_CHANGED,
            'from_status' => OrderStatus::BUDGET_GENERATED,
            'to_status' => OrderStatus::BUDGET_APPROVED,
            'actor_type' => OrderActor::CUSTOMER,
            'actor_id' => null,
        ]);
        $event = OrderEvent::query()->where('order_id', $order->id)->sole();
        $this->assertSame(['channel' => 'public_tracking'], $event->metadata);
    }

    public function test_customer_actor_on_public_budget_rejection(): void
    {
        $order = $this->order(OrderStatus::BUDGET_GENERATED);
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_REJECTED])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::BUDGET_GENERATED,
            'to_status' => OrderStatus::BUDGET_REJECTED,
            'actor_type' => OrderActor::CUSTOMER,
        ]);
    }

    public function test_customer_notice_and_pickup_record_transition_and_acknowledgement(): void
    {
        $order = $this->order(OrderStatus::SERVICE_COMPLETED, ['service_cost' => 0, 'parts_value' => 0, 'service_value' => 0]);
        Auth::logout();

        $this->post(route('orders.notification.acknowledge', $order->tracking_token))->assertSessionHas('success');
        $this->post(route('orders.pickup.acknowledge', $order->tracking_token))->assertSessionHas('success');

        $events = OrderEvent::query()->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertSame([
            [OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED],
            [OrderEvent::TYPE_CUSTOMER_NOTIFICATION_ACKNOWLEDGED, OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED],
            [OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::DELIVERED],
            [OrderEvent::TYPE_CUSTOMER_PICKUP_ACKNOWLEDGED, OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::DELIVERED],
        ], $events->map(fn (OrderEvent $e) => [$e->event_type, $e->from_status, $e->to_status])->all());
        $this->assertTrue($events->every(fn (OrderEvent $e) => $e->actor_type === OrderActor::CUSTOMER && $e->actor_id === null));
    }

    public function test_acknowledgement_without_status_change_has_no_to_status(): void
    {
        $order = $this->order(OrderStatus::CUSTOMER_NOTIFIED);
        Auth::logout();

        $this->post(route('orders.notification.acknowledge', $order->tracking_token))->assertSessionHas('success');

        $event = OrderEvent::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderEvent::TYPE_CUSTOMER_NOTIFICATION_ACKNOWLEDGED, $event->event_type);
        $this->assertSame(OrderStatus::CUSTOMER_NOTIFIED, $event->from_status);
        $this->assertNull($event->to_status);
    }

    public function test_system_actor_on_maintenance_contract_order(): void
    {
        $technician = $this->technician();
        $contract = MaintenanceContract::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'contract_number' => 1,
            'description' => 'Manutenção de ar-condicionado',
            'monthly_amount' => 150,
            'start_date' => now()->toDateString(),
            'visit_frequency_days' => 30,
            'preferred_technician_id' => $technician->id,
            'next_schedule_date' => now()->addDay()->toDateString(),
        ]);
        Auth::logout();

        $schedule = app(MaintenanceContractService::class)->processVisitGeneration($contract);

        $this->assertNotNull($schedule);
        $this->assertDatabaseHas('order_events', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $schedule->order_id,
            'event_type' => OrderEvent::TYPE_ORDER_CREATED,
            'to_status' => OrderStatus::OPEN,
            'actor_type' => OrderActor::SYSTEM,
            'actor_id' => null,
        ]);
        $this->assertDatabaseHas('order_technician_assignments', [
            'order_id' => $schedule->order_id,
            'technician_id' => $technician->id,
            'assigned_by_type' => OrderActor::SYSTEM,
            'assigned_by' => null,
        ]);
    }

    // ------------------------------------------------------------ regressão

    public function test_regression_without_reason_is_rejected(): void
    {
        $order = $this->order(OrderStatus::SERVICE_COMPLETED);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
        ]))->assertSessionHasErrors('status_reason');

        $this->assertSame(OrderStatus::SERVICE_COMPLETED, (int) $order->fresh()->service_status);
        $this->assertSame(0, OrderEvent::query()->where('order_id', $order->id)->count());
    }

    public function test_correction_is_distinguished_from_regression(): void
    {
        $order = $this->order(OrderStatus::SERVICE_COMPLETED);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
            'status_change_kind' => OrderStatus::KIND_CORRECTION,
            'status_reason' => 'Concluído marcado por engano',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_STATUS_CHANGED,
            'from_status' => OrderStatus::SERVICE_COMPLETED,
            'to_status' => OrderStatus::REPAIR_IN_PROGRESS,
            'transition_kind' => OrderStatus::KIND_CORRECTION,
            'reason' => 'Concluído marcado por engano',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::REPAIR_IN_PROGRESS,
            'note' => 'Concluído marcado por engano',
        ]);
    }

    public function test_reopen_of_delivered_order_is_an_explicit_event(): void
    {
        $order = $this->order(OrderStatus::DELIVERED);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
            'status_change_kind' => OrderStatus::KIND_REOPEN,
            'status_reason' => 'Defeito voltou no dia seguinte',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_ORDER_REOPENED,
            'from_status' => OrderStatus::DELIVERED,
            'to_status' => OrderStatus::REPAIR_IN_PROGRESS,
            'transition_kind' => OrderStatus::KIND_REOPEN,
            'reason' => 'Defeito voltou no dia seguinte',
            'actor_id' => $this->user->id,
        ]);
    }

    public function test_cancellation_and_not_executed_require_reason(): void
    {
        foreach ([OrderStatus::CANCELLED, OrderStatus::SERVICE_NOT_EXECUTED] as $target) {
            $order = $this->order(OrderStatus::OPEN);

            $this->put(route('app.orders.update', $order), $this->payload($order, ['service_status' => $target]))
                ->assertSessionHasErrors('status_reason');

            $this->put(route('app.orders.update', $order), $this->payload($order, [
                'service_status' => $target,
                'status_reason' => 'Cliente não autorizou',
            ]))->assertSessionHasNoErrors();

            $this->assertDatabaseHas('order_events', [
                'order_id' => $order->id,
                'to_status' => $target,
                'reason' => 'Cliente não autorizou',
            ]);
        }
    }

    // --------------------------------------------------------------- técnico

    public function test_technician_change_closes_previous_assignment_and_opens_new_one(): void
    {
        $joao = $this->technician();
        $maria = $this->technician();
        $order = $this->order(OrderStatus::OPEN, ['user_id' => null]);

        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => $joao->id]))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-02 14:00:00');
        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => $maria->id]))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => null]))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-04 08:00:00');
        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => $joao->id]))->assertSessionHasNoErrors();
        Carbon::setTestNow();

        $this->assertSame($joao->id, (int) $order->fresh()->user_id);

        $assignments = OrderTechnicianAssignment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(
            [
                [$joao->id, '2026-10-01 09:00:00', '2026-10-02 14:00:00'],
                [$maria->id, '2026-10-02 14:00:00', '2026-10-03 10:00:00'],
                [$joao->id, '2026-10-04 08:00:00', null],
            ],
            $assignments->map(fn ($a) => [
                (int) $a->technician_id,
                $a->assigned_at->toDateTimeString(),
                $a->unassigned_at?->toDateTimeString(),
            ])->all()
        );

        $this->assertSame(
            [
                [OrderEvent::TYPE_TECHNICIAN_ASSIGNED, $joao->id, null],
                [OrderEvent::TYPE_TECHNICIAN_REASSIGNED, $maria->id, $joao->id],
                [OrderEvent::TYPE_TECHNICIAN_UNASSIGNED, null, $maria->id],
                [OrderEvent::TYPE_TECHNICIAN_ASSIGNED, $joao->id, null],
            ],
            OrderEvent::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OrderEvent $e) => [$e->event_type, $e->technician_id ? (int) $e->technician_id : null, $e->previous_technician_id ? (int) $e->previous_technician_id : null])
                ->all()
        );

        // Quem era o responsável em determinado momento?
        $responsibleAt = fn (string $moment) => OrderTechnicianAssignment::query()
            ->where('order_id', $order->id)->activeAt(Carbon::parse($moment))->value('technician_id');

        $this->assertSame($joao->id, (int) $responsibleAt('2026-10-01 12:00:00'));
        $this->assertSame($maria->id, (int) $responsibleAt('2026-10-02 14:00:00'));
        $this->assertNull($responsibleAt('2026-10-03 18:00:00'));
    }

    public function test_same_technician_does_not_duplicate_events(): void
    {
        $technician = $this->technician();
        $order = $this->order(OrderStatus::OPEN, ['user_id' => null]);

        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => $technician->id]));
        $this->put(route('app.orders.update', $order), $this->payload($order, ['user_id' => $technician->id, 'model' => 'Outro']));

        $this->assertSame(1, OrderTechnicianAssignment::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OrderEvent::query()->where('order_id', $order->id)->count());
    }

    public function test_legacy_order_reassignment_does_not_invent_previous_period(): void
    {
        $legacy = $this->technician();
        $new = $this->technician();
        // OS anterior à implantação: user_id preenchido, sem atribuição registrada.
        $order = $this->order(OrderStatus::OPEN, ['user_id' => $legacy->id]);

        app(OrderTechnicianAssignmentService::class)->assign($order, $new->id, OrderActor::user($this->user));

        $this->assertSame(1, OrderTechnicianAssignment::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => OrderEvent::TYPE_TECHNICIAN_REASSIGNED,
            'technician_id' => $new->id,
            'previous_technician_id' => $legacy->id,
        ]);
    }

    // --------------------------------------------------------- multitenancy

    public function test_events_of_tenant_a_are_not_visible_to_tenant_b(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        app(OrderStatusService::class)->transition($order, OrderStatus::IN_DIAGNOSIS, OrderActor::user($this->user));

        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->forTenant($otherTenant->id)->create();
        $this->withSession(['tenant_id' => $otherTenant->id])->actingAs($otherUser);

        $this->assertSame(0, OrderEvent::query()->count());
        $this->assertSame(0, OrderTechnicianAssignment::query()->count());
        $this->assertSame(1, OrderEvent::withoutGlobalScopes()->count());
    }

    public function test_technician_from_another_tenant_cannot_be_assigned(): void
    {
        $order = $this->order(OrderStatus::OPEN, ['user_id' => null]);
        $foreignTechnician = User::factory()->forTenant(Tenant::factory()->create()->id)->create(['roles' => User::ROLE_TECHNICIAN]);

        try {
            app(OrderTechnicianAssignmentService::class)->assign($order, $foreignTechnician->id, OrderActor::user($this->user));
            $this->fail('Atribuição entre tenants deveria falhar.');
        } catch (ValidationException) {
        }

        $this->assertNull($order->fresh()->user_id);
        $this->assertSame(0, OrderTechnicianAssignment::withoutGlobalScopes()->count());
        $this->assertSame(0, OrderEvent::withoutGlobalScopes()->count());
    }

    public function test_records_with_tenant_different_from_order_are_refused(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $otherTenant = Tenant::factory()->create();

        try {
            OrderEvent::create([
                'tenant_id' => $otherTenant->id,
                'order_id' => $order->id,
                'event_type' => OrderEvent::TYPE_STATUS_CHANGED,
                'actor_type' => OrderActor::SYSTEM,
                'occurred_at' => now(),
            ]);
            $this->fail('Evento com tenant divergente deveria falhar.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        OrderTechnicianAssignment::create([
            'tenant_id' => $otherTenant->id,
            'order_id' => $order->id,
            'technician_id' => $this->user->id,
            'assigned_by_type' => OrderActor::SYSTEM,
            'assigned_at' => now(),
        ]);
    }

    public function test_service_takes_tenant_from_order_not_from_session(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        Auth::logout();
        session()->flush();

        app(OrderStatusService::class)->transition($order, OrderStatus::IN_DIAGNOSIS, OrderActor::system());

        $event = OrderEvent::withoutGlobalScopes()->where('order_id', $order->id)->sole();
        $this->assertSame($this->tenant->id, (int) $event->tenant_id);
        $this->assertSame(OrderActor::SYSTEM, $event->actor_type);
    }

    public function test_order_without_tenant_cannot_receive_events(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $order->forceFill(['tenant_id' => null])->saveQuietly();

        $this->expectException(LogicException::class);
        OrderEventRecorder::tenantOf($order);
    }

    // ------------------------------------------------- imutabilidade/segurança

    public function test_events_are_immutable(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $event = app(OrderEventRecorder::class)->record($order, OrderEvent::TYPE_STATUS_CHANGED, OrderActor::system());

        try {
            $event->update(['reason' => 'alterado']);
            $this->fail('Evento não pode ser alterado.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_closed_assignment_cannot_be_reopened_or_rewritten(): void
    {
        $technician = $this->technician();
        $order = $this->order(OrderStatus::OPEN, ['user_id' => null]);
        $service = app(OrderTechnicianAssignmentService::class);
        $service->assign($order, $technician->id, OrderActor::user($this->user));
        $service->assign($order, null, OrderActor::user($this->user));

        $assignment = OrderTechnicianAssignment::query()->where('order_id', $order->id)->sole();

        $this->expectException(LogicException::class);
        $assignment->update(['unassigned_at' => null]);
    }

    public function test_metadata_never_stores_sensitive_keys(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $event = app(OrderEventRecorder::class)->record($order, OrderEvent::TYPE_STATUS_CHANGED, OrderActor::system(), [], [
            'password' => '1234',
            'public_access_key' => 'ABC',
            'tracking_token' => 'tok',
            'channel' => 'public_tracking',
        ]);

        $this->assertSame(['channel' => 'public_tracking'], $event->fresh()->metadata);
    }

    // ---------------------------------------------------- fluxos existentes

    public function test_warranty_return_creation_keeps_working_and_records_source(): void
    {
        $source = $this->order(OrderStatus::DELIVERED, [
            'delivery_date' => now()->subDays(5),
            'warranty_days' => 90,
            'warranty_expires_at' => now()->addDays(85),
        ]);

        $this->post(route('app.orders.store'), [
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Notebook',
            'defect' => 'Mesmo defeito',
            'service_status' => OrderStatus::OPEN,
            'is_warranty_return' => true,
            'warranty_source_order_id' => $source->id,
            'delivery_forecast' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) $order->is_warranty_return);
        $event = OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_ORDER_CREATED)->sole();
        $this->assertSame($source->id, $event->metadata['warranty_source_order_id']);
    }

    public function test_full_flow_completion_and_delivery_builds_reliable_timeline(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $actor = OrderActor::user($this->user);
        $service = app(OrderStatusService::class);

        foreach ([
            OrderStatus::IN_DIAGNOSIS,
            OrderStatus::BUDGET_GENERATED,
            OrderStatus::BUDGET_APPROVED,
            OrderStatus::AWAITING_PART,
            OrderStatus::REPAIR_IN_PROGRESS,
            OrderStatus::SERVICE_COMPLETED,
            OrderStatus::CUSTOMER_NOTIFIED,
            OrderStatus::DELIVERED,
        ] as $status) {
            $service->transition($order, $status, $actor);
        }

        $timeline = OrderEvent::query()->where('order_id', $order->id)
            ->whereIn('event_type', OrderEvent::STATUS_TIMELINE_TYPES)->orderBy('id')->get();

        $this->assertCount(8, $timeline);
        // Cada evento começa onde o anterior terminou.
        $previous = OrderStatus::OPEN;
        foreach ($timeline as $event) {
            $this->assertSame($previous, $event->from_status);
            $previous = $event->to_status;
        }
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->fresh()->service_status);
    }

    private function technician(): User
    {
        return User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN, 'status' => 1]);
    }

    private function order(int $status, array $attributes = []): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'service_status' => $status,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_type' => Order::TYPE_EQUIPMENT,
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $order->user_id,
            'model' => $order->model ?? 'Modelo',
            'password' => null,
            'defect' => 'Defeito',
            'state_conservation' => 'Usado',
            'accessories' => 'Nenhum',
            'budget_description' => null,
            'budget_value' => '0,00',
            'services_performed' => null,
            'parts_value' => '0,00',
            'service_value' => '0,00',
            'service_cost' => '0,00',
            'delivery_date' => null,
            'service_status' => (int) $order->service_status,
            'delivery_forecast' => $order->delivery_forecast,
            'observations' => null,
        ], $overrides);
    }
}
