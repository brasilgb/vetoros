<?php

namespace Tests\Feature\App;

use App\Models\App\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderWhatsappFinancialValuesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($user);
    }

    public function test_services_only_order_exposes_the_same_value_for_services_and_total(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'order_number' => 900001,
            'service_value' => 950,
            'parts_value' => 0,
            'manual_parts_value' => 0,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'service_cost' => 950,
        ]);

        $this->get(route('app.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.whatsapp_values.services', fn ($value) => (float) $value === 950.0)
                ->where('orders.data.0.whatsapp_values.total', fn ($value) => (float) $value === 950.0));
    }

    public function test_services_exclude_parts_and_share_the_discount_proportionally(): void
    {
        // Serviços 1000 + peças 300 − desconto 50 = total 1250. A parcela do desconto que cabe
        // aos serviços é 50 − 50×300/1300 = 38,46, então os serviços líquidos ficam em 961,54.
        Order::factory()->forTenant($this->tenant->id)->create([
            'order_number' => 900002,
            'service_value' => 1000,
            'parts_value' => 300,
            'manual_parts_value' => 300,
            'discount_amount' => 50,
            'surcharge_amount' => 0,
            'service_cost' => 1250,
        ]);

        $this->get(route('app.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.whatsapp_values.services', fn ($value) => (float) $value === 961.54)
                ->where('orders.data.0.whatsapp_values.total', fn ($value) => (float) $value === 1250.0));
    }

    public function test_order_without_defined_value_exposes_null_instead_of_zero(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'order_number' => 900003,
            'service_value' => null,
            'parts_value' => null,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'service_cost' => null,
        ]);

        $this->get(route('app.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.whatsapp_values', ['services' => null, 'total' => null]));
    }
}
