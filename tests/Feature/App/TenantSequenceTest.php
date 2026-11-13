<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\Part;
use App\Models\Tenant;
use App\Support\TenantSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_number_is_calculated_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        Customer::factory()->forTenant($tenant->id)->create(['customer_number' => 7]);
        Customer::factory()->forTenant($otherTenant->id)->create(['customer_number' => 20]);

        $this->assertSame(8, TenantSequence::next(Customer::class, 'customer_number', $tenant->id));
        $this->assertSame(21, TenantSequence::next(Customer::class, 'customer_number', $otherTenant->id));
    }

    public function test_next_number_starts_at_one_for_empty_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        Customer::factory()->forTenant($otherTenant->id)->create(['customer_number' => 20]);

        $this->assertSame(1, TenantSequence::next(Customer::class, 'customer_number', $tenant->id));
    }

    public function test_numeric_string_numbers_are_compared_as_integers(): void
    {
        $tenant = Tenant::factory()->create();

        Part::factory()->forTenant($tenant->id)->create([
            'part_number' => '9',
            'reference_number' => 'REF-9',
        ]);
        Part::factory()->forTenant($tenant->id)->create([
            'part_number' => '10',
            'reference_number' => 'REF-10',
        ]);

        $this->assertSame(11, TenantSequence::next(Part::class, 'part_number', $tenant->id));
    }

    public function test_order_number_uses_an_aggregate_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        Order::factory()->forTenant($tenant->id)->create(['order_number' => 41]);
        Order::factory()->forTenant($tenant->id)->create(['order_number' => 9]);
        Order::factory()->forTenant($otherTenant->id)->create(['order_number' => 500]);

        DB::enableQueryLog();
        $this->assertSame(42, TenantSequence::next(Order::class, 'order_number', $tenant->id));
        $this->assertStringContainsString('max(', strtolower(DB::getQueryLog()[0]['query']));
        DB::disableQueryLog();

        $this->assertSame(1, TenantSequence::next(Order::class, 'order_number', Tenant::factory()->create()->id));
    }
}
