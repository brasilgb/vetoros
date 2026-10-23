<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_it_stores_an_equipment_type_with_only_its_name(): void
    {
        $response = $this->post(route('app.register-equipments.store'), [
            'equipment' => 'Notebook',
            'chart' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('app.register-equipments.index'));
        $this->assertDatabaseHas('equipment', [
            'tenant_id' => $this->tenant->id,
            'equipment' => 'Notebook',
            'chart' => true,
        ]);
    }

    public function test_it_ignores_a_legacy_group_sent_by_the_client(): void
    {
        $response = $this->post(route('app.register-equipments.store'), [
            'equipment' => 'Smartphone',
            'kind' => 'mobile',
            'chart' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('equipment', [
            'tenant_id' => $this->tenant->id,
            'equipment' => 'Smartphone',
            'kind' => null,
        ]);
    }

    public function test_a_type_created_with_only_a_name_can_be_used_in_an_order(): void
    {
        Other::factory()->forTenant($this->tenant->id)->create([
            'enable_finance' => true,
            'enablesales' => true,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        $this->post(route('app.register-equipments.store'), [
            'equipment' => 'Notebook',
            'chart' => false,
        ])->assertSessionHasNoErrors();

        $equipment = Equipment::query()->where('equipment', 'Notebook')->firstOrFail();

        $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell Inspiron',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ])->assertRedirect(route('app.orders.index'));

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $this->tenant->id,
            'equipment_id' => $equipment->id,
        ]);
    }

    public function test_it_requires_a_name(): void
    {
        $response = $this->post(route('app.register-equipments.store'), [
            'equipment' => '',
            'chart' => false,
        ]);

        $response->assertSessionHasErrors('equipment');
    }

    public function test_it_updates_only_the_name_and_keeps_existing_legacy_group(): void
    {
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create([
            'equipment' => 'Desktop',
            'kind' => 'pc',
        ]);

        $response = $this->patch(route('app.register-equipments.update', $equipment), [
            'equipment' => 'Desktop Gamer',
            'chart' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('app.register-equipments.index'));
        $this->assertDatabaseHas('equipment', [
            'id' => $equipment->id,
            'equipment' => 'Desktop Gamer',
            'chart' => true,
            'kind' => 'pc',
        ]);
    }
}
