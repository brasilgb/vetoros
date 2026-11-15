<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeactivationReleasesOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN, 'status' => 1]);
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->admin);
    }

    public function test_deactivating_technician_releases_open_orders_and_keeps_closed_ones(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN, 'status' => 1]);
        $open = $this->order(OrderStatus::OPEN, $technician);
        $delivered = $this->order(OrderStatus::DELIVERED, $technician);

        $this->put(route('app.users.update', $technician), $this->payload($technician, ['status' => 0]))
            ->assertRedirect();

        $this->assertNull($open->fresh()->user_id);
        $this->assertSame($technician->id, $delivered->fresh()->user_id);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $open->id,
            'event_type' => OrderEvent::TYPE_TECHNICIAN_UNASSIGNED,
            'previous_technician_id' => $technician->id,
        ]);
    }

    public function test_editing_active_technician_keeps_their_orders(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN, 'status' => 1]);
        $open = $this->order(OrderStatus::OPEN, $technician);

        $this->put(route('app.users.update', $technician), $this->payload($technician, ['status' => 1]))
            ->assertRedirect();

        $this->assertSame($technician->id, $open->fresh()->user_id);
    }

    private function order(int $status, User $technician): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $technician->id,
            'service_status' => $status,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function payload(User $technician, array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => $technician->name,
            'email' => $technician->email,
            'roles' => User::ROLE_TECHNICIAN,
            'status' => 1,
            'can_view_all_orders' => 0,
        ], $overrides);
    }
}
