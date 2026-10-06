<?php

namespace Tests\Feature\App;

use App\Models\App\CashSession;
use App\Models\App\CashSessionMovement;
use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Schedule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        Other::factory()->forTenant($this->tenant->id)->create([
            'enable_technician_schedule_notifications' => true,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_it_creates_schedule_with_required_customer_order_and_send_to_technician_flag(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'defect' => 'Instalação em campo',
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->post(route('app.schedules.store'), [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'schedules' => now()->addDay()->format('Y-m-d H:i:s'),
            'service' => 'Visita técnica externa',
            'status' => 1,
            'send_to_technician' => true,
        ]);

        $response->assertRedirect(route('app.schedules.index'));

        $this->assertDatabaseHas('schedules', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'send_to_technician' => true,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::OPEN,
        ]);
    }

    public function test_it_creates_schedule_without_linked_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        $response = $this->post(route('app.schedules.store'), [
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->user->id,
            'schedules' => now()->addDay()->format('Y-m-d H:i:s'),
            'service' => 'Retirada de equipamento no cliente',
            'details' => 'Cliente informou que o equipamento está embalado na recepção.',
            'material_checklist' => [
                ['name' => 'Etiqueta de identificação', 'quantity' => 2],
                ['name' => 'Fonte de teste', 'quantity' => 1],
                'Lacre',
            ],
            'technician_checklist' => [
                'Conferir equipamento',
                'Orientar cliente',
            ],
            'status' => 1,
            'send_to_technician' => false,
        ]);

        $response->assertRedirect(route('app.schedules.index'));

        $this->assertDatabaseHas('schedules', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->user->id,
            'service' => 'Retirada de equipamento no cliente',
            'details' => 'Cliente informou que o equipamento está embalado na recepção.',
        ]);

        $schedule = Schedule::query()->firstOrFail();
        $this->assertSame($this->sortedItems([
            ['name' => 'Etiqueta de identificação', 'quantity' => 2, 'part_id' => null, 'used' => false],
            ['name' => 'Fonte de teste', 'quantity' => 1, 'part_id' => null, 'used' => false],
            ['name' => 'Lacre', 'quantity' => 1, 'part_id' => null, 'used' => false],
        ]), $this->sortedItems($schedule->material_checklist));
        $this->assertSame([
            'Conferir equipamento',
            'Orientar cliente',
        ], $schedule->technician_checklist);
    }

    public function test_it_does_not_change_linked_order_status_when_schedule_is_closed(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::OPEN,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'status' => 1,
        ]);

        $response = $this->patch(route('app.schedules.update', $schedule), [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'schedules' => now()->addDay()->format('Y-m-d H:i:s'),
            'service' => 'Retorno técnico externo',
            'status' => 3,
            'send_to_technician' => false,
        ]);

        $response->assertRedirect(route('app.schedules.show', ['schedule' => $schedule->id]));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::OPEN,
        ]);
    }

    public function test_it_rejects_schedule_order_from_another_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $otherCustomer = Customer::factory()->forTenant($this->tenant->id)->create();
        $otherOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $otherCustomer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('app.schedules.store'), [
            'customer_id' => $customer->id,
            'order_id' => $otherOrder->id,
            'user_id' => $this->user->id,
            'schedules' => now()->addDay()->format('Y-m-d H:i:s'),
            'service' => 'Visita técnica externa',
            'status' => 1,
            'send_to_technician' => false,
        ]);

        $response->assertRedirect(config('app.url'));
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_it_disables_send_to_technician_when_setting_is_off(): void
    {
        Other::query()
            ->where('tenant_id', $this->tenant->id)
            ->update(['enable_technician_schedule_notifications' => false]);

        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('app.schedules.store'), [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'schedules' => now()->addDay()->format('Y-m-d H:i:s'),
            'service' => 'Visita técnica externa',
            'status' => 1,
            'send_to_technician' => true,
        ]);

        $response->assertRedirect(route('app.schedules.index'));

        $this->assertDatabaseHas('schedules', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'send_to_technician' => false,
        ]);
    }

    public function test_it_blocks_schedule_deletion_when_linked_order_has_payment(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
        ]);
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'amount' => 100,
            'payment_method' => 'pix',
            'paid_at' => now(),
        ]);

        $response = $this->delete(route('app.schedules.destroy', $schedule));

        $response->assertRedirect(route('app.schedules.index'));
        $response->assertSessionHas(
            'error',
            'Não é possível excluir este agendamento porque ele possui atendimento técnico ou pagamento registrado.'
        );

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
        ]);
    }

    public function test_it_registers_schedule_local_payment_into_open_cashier(): void
    {
        $cashSession = CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now()->subHour(),
            'opening_balance' => 100,
            'status' => 'open',
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->user->id,
            'local_payment_received' => true,
            'local_payment_amount' => 180.50,
            'local_payment_received_at' => now(),
            'local_payment_user_id' => $this->user->id,
        ]);

        $response = $this->post(route('app.schedules.local-payment-cashier', $schedule));

        $response->assertSessionHas('success', 'Pagamento do atendimento inserido no caixa.');

        $this->assertDatabaseHas('cash_session_movements', [
            'tenant_id' => $this->tenant->id,
            'cash_session_id' => $cashSession->id,
            'user_id' => $this->user->id,
            'type' => CashSessionMovement::TYPE_ENTRY,
            'amount' => 180.50,
            'description' => 'Atendimento do agendamento #'.$schedule->schedules_number,
            'source_type' => 'schedule',
            'source_id' => $schedule->id,
        ]);

        $schedule->refresh();
        $this->assertSame($cashSession->id, (int) $schedule->local_payment_cash_session_id);
        $this->assertNotNull($schedule->local_payment_cash_registered_at);

        $this->post(route('app.schedules.local-payment-cashier', $schedule))
            ->assertSessionHas('error', 'Este pagamento local já foi inserido no caixa.');

        $this->assertSame(1, CashSessionMovement::query()->where('source_type', 'schedule')->where('source_id', $schedule->id)->count());
    }

    public function test_company_defines_requested_service_closure_price(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'service_closure_status' => 'requested',
            'service_closure_requested_at' => now(),
        ]);

        $this->patch(route('app.schedules.service-closure-price', $schedule), [
            'amount' => 245.90,
        ])->assertSessionHas('success', 'Valor do atendimento definido e liberado para o técnico.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'service_closure_status' => 'priced',
            'service_closure_amount' => 245.90,
            'service_closure_priced_by' => $this->user->id,
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
