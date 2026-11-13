<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * VETOROS-PERFORMANCE-OS-02: o custo das telas de OS não pode crescer com o histórico.
 */
class OrderPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    private function makeOrders(int $count, array $attributes = []): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        Order::factory()->forTenant($this->tenant->id)->count($count)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            ...$attributes,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_listing_query_count_does_not_grow_with_rows_on_the_page(): void
    {
        $this->makeOrders(3, ['service_status' => OrderStatus::BUDGET_GENERATED]);
        $this->get(route('app.orders.index'))->assertOk(); // aquecimento (sessão, caches da requisição)
        $few = $this->countQueries(fn () => $this->get(route('app.orders.index'))->assertOk());

        $this->makeOrders(15, ['service_status' => OrderStatus::BUDGET_GENERATED]);
        $many = $this->countQueries(fn () => $this->get(route('app.orders.index'))->assertOk());

        $this->assertSame($few, $many, "Consultas por linha na listagem: {$few} com 3 OS, {$many} com 18 OS.");
    }

    public function test_listing_keeps_payment_and_budget_flags(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays(10),
            'service_cost' => 300,
        ]);
        DB::table('order_payments')->insert([
            'order_id' => $order->id, 'amount' => 100,
            'payment_method' => 'pix', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('app.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.total_paid', fn ($value) => (float) $value === 100.0)
                ->where('orders.data.0.pending_payment_follow_up', true));
    }

    /** VETOROS-PERFORMANCE-OS-04: sem chave pública, criar e listar OS não roda bcrypt nenhum. */
    public function test_creating_and_listing_orders_runs_no_bcrypt(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $this->get(route('app.orders.index'))->assertOk();

        Hash::spy();

        $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ])->assertRedirect();
        $this->get(route('app.orders.index'))->assertOk();
        $this->get(route('app.orders.show', Order::query()->firstOrFail()))->assertOk();

        Hash::shouldNotHaveReceived('make');
        Hash::shouldNotHaveReceived('check');
    }

    public function test_create_form_does_not_ship_order_history(): void
    {
        $this->makeOrders(5);

        $this->get(route('app.orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('app/orders/create-order')
                ->has('equipments')
                ->missing('models')
                ->missing('warrantySourceOrders')
                ->missing('activeOrders'));
    }

    public function test_context_options_are_filtered_by_customer_and_equipment(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $other = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $warranty = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id, 'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED, 'delivery_date' => now()->subDays(5),
            'warranty_expires_at' => now()->addDays(60),
        ]);
        $active = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id, 'equipment_id' => $equipment->id, 'service_status' => OrderStatus::OPEN,
            'delivery_date' => null, 'warranty_expires_at' => null,
        ]);
        Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $other->id, 'equipment_id' => $equipment->id, 'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->getJson(route('app.orders.context-options', [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
        ]))->assertOk();

        $this->assertSame([$warranty->id], array_column($response->json('warrantySourceOrders'), 'id'));
        $this->assertSame([$active->id], array_column($response->json('activeOrders'), 'id'));
    }

    public function test_context_options_reject_customer_from_another_tenant(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->forTenant($foreignTenant->id)->create();

        $this->getJson(route('app.orders.context-options', ['customer_id' => $foreignCustomer->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_context_options_respect_technician_visibility(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $own = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id, 'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::OPEN, 'user_id' => $technician->id,
        ]);
        Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id, 'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::OPEN, 'user_id' => $this->user->id,
        ]);

        if ($technician->canViewAllOrders()) {
            $this->markTestSkipped('Perfil técnico padrão já vê todas as OS.');
        }

        $ids = array_column($this->actingAs($technician)
            ->getJson(route('app.orders.context-options', ['customer_id' => $customer->id, 'equipment_id' => $equipment->id]))
            ->assertOk()
            ->json('activeOrders'), 'id');

        $this->assertSame([$own->id], $ids);
    }

    public function test_tenant_setting_reads_are_memoized_and_invalidated_on_save(): void
    {
        $this->assertTrue(Other::financeEnabled($this->tenant->id));

        $queries = $this->countQueries(fn () => Other::financeEnabled($this->tenant->id));
        $this->assertSame(0, $queries);

        Other::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->update(['enable_finance' => false]);

        $this->assertFalse(Other::financeEnabled($this->tenant->id));
    }
}
