<?php

namespace Tests\Feature\App;

use App\Models\App\Checklist;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Image;
use App\Models\App\Order;
use App\Models\App\Part;
use App\Models\App\Schedule;
use App\Models\App\ScheduleImage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TechnicianScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
    }

    public function test_technician_lists_only_sent_own_schedules(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cliente Campo',
            'city' => 'Porto Alegre',
        ]);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create([
            'equipment' => 'Notebook',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
            'model' => 'Dell Inspiron',
            'defect' => 'Nao liga',
        ]);
        $sentSchedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => false,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.schedules.index'));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.data.0.id', $sentSchedule->id)
            ->assertJsonPath('result.data.0.customer.name', 'Cliente Campo')
            ->assertJsonPath('result.data.0.order.model', 'Dell Inspiron')
            ->assertJsonPath('result.data.0.order.equipment.equipment', 'Notebook')
            ->assertJsonCount(1, 'result.data');
    }

    public function test_technician_app_lists_schedule_without_order_and_updates_material_checklist(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cliente Externo',
        ]);
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Fonte 12V',
            'type' => 'part',
            'status' => true,
            'quantity' => 5,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'service' => 'Retirada externa',
            'material_checklist' => [
                ['name' => 'Etiqueta', 'quantity' => 1, 'part_id' => null, 'used' => false],
            ],
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.schedules.index'))
            ->assertOk()
            ->assertJsonPath('result.data.0.id', $schedule->id)
            ->assertJsonPath('result.data.0.order', null)
            ->assertJsonPath('result.data.0.service', 'Retirada externa')
            ->assertJsonPath('result.data.0.material_checklist.0.name', 'Etiqueta')
            ->assertJsonPath('result.data.0.material_checklist.0.quantity', 1);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'materials' => [
                    ['name' => 'Fonte 12V', 'quantity' => 2, 'part_id' => $part->id, 'used' => false],
                    ['name' => 'Cabo HDMI', 'quantity' => 1],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.material_checklist.0.name', 'Fonte 12V')
            ->assertJsonPath('result.material_checklist.0.quantity', 2)
            ->assertJsonPath('result.material_checklist.0.part_id', $part->id)
            ->assertJsonPath('result.material_checklist.0.used', false)
            ->assertJsonPath('result.material_checklist.1.name', 'Cabo HDMI');

        $this->assertSame(5, (int) $part->fresh()->quantity);
        $this->assertSame($this->sortedItems([
            ['name' => 'Fonte 12V', 'quantity' => 2, 'part_id' => $part->id, 'used' => false],
            ['name' => 'Cabo HDMI', 'quantity' => 1, 'part_id' => null, 'used' => false],
        ]), $this->sortedItems($schedule->refresh()->material_checklist));

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'materials' => [
                    ['name' => 'Fonte 12V', 'quantity' => 2, 'part_id' => $part->id, 'used' => true],
                    ['name' => 'Cabo HDMI', 'quantity' => 1, 'used' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('result.material_checklist.0.used', true);

        $this->assertSame(3, (int) $part->fresh()->quantity);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'user_id' => $this->technician->id,
            'movement_type' => 'uso_os',
            'quantity' => 2,
            'reason' => 'Uso no agendamento #'.$schedule->schedules_number,
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'materials' => [
                    ['name' => 'Fonte 12V', 'quantity' => 2, 'part_id' => $part->id, 'used' => false],
                    ['name' => 'Cabo HDMI', 'quantity' => 1, 'used' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('result.material_checklist.0.used', false);

        $this->assertSame(5, (int) $part->fresh()->quantity);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'user_id' => $this->technician->id,
            'movement_type' => 'devolucao',
            'quantity' => 2,
            'reason' => 'Devolução de peça do agendamento #'.$schedule->schedules_number,
        ]);
    }

    public function test_technician_updates_checklist_stored_on_independent_schedule(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'technician_checklist' => [
                'Testar funcionamento',
                'Orientar cliente',
            ],
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'items' => [
                    'Testar funcionamento',
                    'Item não cadastrado',
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.technician_checklist.0', 'Testar funcionamento')
            ->assertJsonPath('result.technician_checklist.1', 'Orientar cliente')
            ->assertJsonPath('result.technician_checklist_items.0', 'Testar funcionamento')
            ->assertJsonMissing(['Item não cadastrado']);

        $this->assertSame(
            ['Testar funcionamento'],
            $schedule->refresh()->technician_checklist_items
        );
        $this->assertNull($schedule->technician_checklist_completed_at);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'items' => [
                    'Testar funcionamento',
                    'Orientar cliente',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('result.technician_checklist_items.1', 'Orientar cliente');

        $this->assertNotNull($schedule->refresh()->technician_checklist_completed_at);
    }

    public function test_technician_cannot_open_schedule_from_another_technician(): void
    {
        $otherTechnician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $otherTechnician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $otherTechnician->id,
            'send_to_technician' => true,
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.schedules.show', $schedule))
            ->assertNotFound();
    }

    public function test_non_technician_cannot_use_technician_schedule_api(): void
    {
        $operator = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_OPERATOR,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson(route('api.technician.schedules.index'))
            ->assertForbidden();
    }

    public function test_technician_dashboard_returns_summary_and_next_schedule(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cliente Prioritario',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
            'service_status' => OrderStatus::OPEN,
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
        ]);
        $nextSchedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'schedules' => now()->addHour(),
            'status' => 1,
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'schedules' => now()->addHours(2),
            'status' => 2,
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'schedules' => now()->subHour(),
            'status' => 3,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.dashboard'));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.summary.today', 3)
            ->assertJsonPath('result.summary.pending', 2)
            ->assertJsonPath('result.summary.completed', 1)
            ->assertJsonPath('result.next_schedule.id', $nextSchedule->id)
            ->assertJsonPath('result.next_schedule.customer.name', 'Cliente Prioritario');
    }

    public function test_technician_updates_schedule_status(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            // A factory sorteia o status; fixado para provar que a OS não é alterada.
            'service_status' => OrderStatus::OPEN,
            'user_id' => $this->technician->id,
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 1,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.status', $schedule), [
                'status' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.status', 2)
            ->assertJsonPath('result.status_label', 'Em atendimento');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::OPEN,
        ]);
    }

    public function test_technician_schedule_payload_includes_equipment_checklist_items(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        Checklist::factory()->forTenant($this->tenant->id)->create([
            'equipment_id' => $equipment->id,
            'checklist' => 'Equipamento instalado, Equipamento testado, Cliente orientado',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.schedules.show', $schedule));

        $response
            ->assertOk()
            ->assertJsonPath('result.order.equipment.checklist_items.0', 'Equipamento instalado')
            ->assertJsonPath('result.order.equipment.checklist_items.1', 'Equipamento testado')
            ->assertJsonPath('result.order.equipment.checklist_items.2', 'Cliente orientado');
    }

    public function test_technician_updates_order_checklist_for_owned_schedule(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        Checklist::factory()->forTenant($this->tenant->id)->create([
            'equipment_id' => $equipment->id,
            'checklist' => 'Equipamento instalado, Equipamento testado, Cliente orientado',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.checklist', $schedule), [
                'items' => [
                    'Equipamento instalado',
                    'Cliente orientado',
                    'Item fora do cadastro',
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.order.technician_checklist_items.0', 'Equipamento instalado')
            ->assertJsonPath('result.order.technician_checklist_items.1', 'Cliente orientado');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
        ]);

        $this->assertSame([
            'Equipamento instalado',
            'Cliente orientado',
        ], $order->refresh()->technician_checklist_items);
        $this->assertNotNull($order->technician_checklist_completed_at);
    }

    public function test_technician_can_revert_started_schedule_before_check_in(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => null,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.status', $schedule), [
                'status' => 1,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.status', 1)
            ->assertJsonPath('result.status_label', 'Aberta');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 1,
            'check_in_at' => null,
        ]);
    }

    public function test_technician_cannot_revert_schedule_after_check_in(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now(),
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.status', $schedule), [
                'status' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
        ]);
    }

    public function test_technician_registers_check_in_with_gps(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 1,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-in', $schedule), [
                'latitude' => -30.034647,
                'longitude' => -51.217658,
                'observations' => 'Cliente confirmou chegada.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.status', 2)
            ->assertJsonPath('result.check_in.latitude', '-30.0346470')
            ->assertJsonPath('result.check_in.longitude', '-51.2176580')
            ->assertJsonPath('result.check_in.observations', 'Cliente confirmou chegada.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
            'check_in_latitude' => -30.034647,
            'check_in_longitude' => -51.217658,
            'check_in_observations' => 'Cliente confirmou chegada.',
        ]);
    }

    public function test_technician_registers_check_out_with_gps(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
            'service_status' => OrderStatus::OPEN,
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
            'service_closure_status' => 'priced',
            'service_closure_amount' => 120.50,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-out', $schedule), [
                'latitude' => -30.035000,
                'longitude' => -51.218000,
                'observations' => 'Atendimento finalizado.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.status', 3)
            ->assertJsonPath('result.check_out.latitude', '-30.0350000')
            ->assertJsonPath('result.check_out.longitude', '-51.2180000')
            ->assertJsonPath('result.check_out.observations', 'Atendimento finalizado.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 3,
            'check_out_latitude' => -30.035000,
            'check_out_longitude' => -51.218000,
            'check_out_observations' => 'Atendimento finalizado.',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::OPEN,
        ]);
    }

    public function test_technician_cannot_check_out_without_saved_report(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
            'technician_diagnosis' => null,
            'technician_solution' => null,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-out', $schedule), [
                'latitude' => -30.03,
                'longitude' => -51.23,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Salve o diagnostico e a solucao antes de finalizar o atendimento.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
            'check_out_at' => null,
        ]);
    }

    public function test_technician_cannot_check_out_with_pending_required_checklist(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        Checklist::factory()->forTenant($this->tenant->id)->create([
            'equipment_id' => $equipment->id,
            'checklist' => 'Equipamento instalado, Equipamento testado',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
            'technician_checklist_items' => ['Equipamento instalado'],
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-out', $schedule), [
                'latitude' => -30.03,
                'longitude' => -51.23,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Conclua e salve o checklist antes de finalizar o atendimento.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
            'check_out_at' => null,
        ]);
    }

    public function test_technician_cannot_check_out_before_check_in(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => null,
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-out', $schedule), [
                'observations' => 'Tentativa sem chegada.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
            'check_out_at' => null,
        ]);
    }

    public function test_technician_cannot_check_out_without_gps_coordinates(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
            'technician_diagnosis' => 'Falha identificada.',
            'technician_solution' => 'Servico concluido.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
            'check_in_latitude' => -30.034647,
            'check_in_longitude' => -51.217658,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.check-out', $schedule), [
                'observations' => 'Tentativa sem GPS.',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 2,
            'check_out_at' => null,
            'check_out_latitude' => null,
            'check_out_longitude' => null,
        ]);
    }

    public function test_technician_uploads_image_for_owned_schedule_order(): void
    {
        $this->app->usePublicPath(storage_path('framework/testing/public'));
        File::deleteDirectory(public_path());

        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('upload'), [
                'order_number' => $order->order_number,
                'filename' => base64_encode('technical-image'),
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Imagem salva com sucesso',
            ]);

        $image = Image::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame($this->tenant->id, $image->tenant_id);
        $this->assertFileExists(public_path('storage/orders/'.$order->order_number.'/'.$image->filename));
    }

    public function test_technician_manages_images_for_owned_schedule_without_order(): void
    {
        $this->app->usePublicPath(storage_path('framework/testing/public'));
        File::deleteDirectory(public_path());

        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.images.store', $schedule), [
                'filename' => base64_encode('schedule-image'),
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Imagem salva com sucesso',
            ])
            ->assertJsonPath('result.schedule_id', $schedule->id);

        $image = ScheduleImage::query()->where('schedule_id', $schedule->id)->firstOrFail();

        $this->assertSame($this->tenant->id, $image->tenant_id);
        $this->assertSame($this->technician->id, $image->user_id);
        $this->assertFileExists(public_path('storage/schedules/'.$schedule->id.'/'.$image->filename));

        $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('api.technician.schedules.images.index', $schedule))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.0.id', $image->id)
            ->assertJsonPath('result.0.schedule_id', $schedule->id);

        $this->actingAs($this->technician, 'sanctum')
            ->deleteJson(route('api.technician.schedules.images.destroy', [$schedule, $image]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Imagem deletada com sucesso!',
            ]);

        $this->assertDatabaseMissing('schedule_images', ['id' => $image->id]);
        $this->assertFileDoesNotExist(public_path('storage/schedules/'.$schedule->id.'/'.$image->filename));
    }

    public function test_technician_updates_report_directly_on_owned_schedule(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.report', $schedule), [
                'technician_diagnosis' => 'Fonte queimada.',
                'technician_solution' => 'Fonte substituida e equipamento testado.',
                'technician_observations' => 'Cliente acompanhou o teste.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.order', null)
            ->assertJsonPath('result.technician_report.diagnosis', 'Fonte queimada.')
            ->assertJsonPath('result.technician_report.solution', 'Fonte substituida e equipamento testado.')
            ->assertJsonPath('result.technician_report.observations', 'Cliente acompanhou o teste.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'technician_diagnosis' => 'Fonte queimada.',
            'technician_solution' => 'Fonte substituida e equipamento testado.',
            'technician_observations' => 'Cliente acompanhou o teste.',
        ]);

        $this->assertNotNull($schedule->refresh()->technician_report_updated_at);
    }

    public function test_technician_records_local_payment_for_owned_schedule_without_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'service_closure_status' => 'priced',
            'service_closure_amount' => 120.50,
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.payment', $schedule), [
                'amount' => 120.50,
                'paid' => true,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.order', null)
            ->assertJsonPath('result.local_payment.received', true)
            ->assertJsonPath('result.local_payment.user_id', $this->technician->id);

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'local_payment_received' => true,
            'local_payment_amount' => 120.50,
            'local_payment_user_id' => $this->technician->id,
        ]);

        $this->assertDatabaseMissing('order_payments', [
            'amount' => 120.50,
        ]);
    }

    public function test_technician_requests_service_closure_before_checkout(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->technician->id,
            'technician_diagnosis' => 'Conector danificado.',
            'technician_solution' => 'Conector substituído.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
            'technician_diagnosis' => 'Conector danificado.',
            'technician_solution' => 'Conector substituído.',
        ]);

        $response = $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.request-closure', $schedule));

        $response
            ->assertOk()
            ->assertJsonPath('result.service_closure.status', 'requested')
            ->assertJsonPath('result.available_actions.can_check_out', false)
            ->assertJsonPath('result.available_actions.can_record_local_payment', false);

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'service_closure_status' => 'requested',
            'service_closure_requested_by' => $this->technician->id,
        ]);
    }

    public function test_technician_cannot_request_service_closure_with_pending_checklist(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        Checklist::factory()->forTenant($this->tenant->id)->create([
            'equipment_id' => $equipment->id,
            'checklist' => 'Equipamento instalado, Equipamento testado',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
            'technician_checklist_items' => ['Equipamento instalado'],
            'technician_diagnosis' => 'Conector danificado.',
            'technician_solution' => 'Conector substituído.',
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->technician->id,
            'send_to_technician' => true,
            'status' => 2,
            'check_in_at' => now()->subMinutes(20),
            'technician_diagnosis' => 'Conector danificado.',
            'technician_solution' => 'Conector substituído.',
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->postJson(route('api.technician.schedules.request-closure', $schedule))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Conclua e salve o checklist antes de solicitar o fechamento.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'service_closure_status' => null,
        ]);
    }

    /** Coluna JSON do MySQL normaliza a ordem das chaves; compara valores e tipos de forma estrita. */
    private function sortedItems(array $items): array
    {
        return array_map(function (array $item) {
            ksort($item);

            return $item;
        }, $items);
    }
}
