<?php

namespace Tests\Unit;

use App\Support\PartCostPolicy;
use PHPUnit\Framework\TestCase;

class PartCostPolicyTest extends TestCase
{
    public function test_weighted_average_with_existing_stock(): void
    {
        // (10 × 20 + 30 × 40) / 40 = 35
        $this->assertSame(35.0, PartCostPolicy::weightedAverage(10, 20.0, 30, 40.0));
    }

    public function test_zero_stock_adopts_purchase_cost(): void
    {
        $this->assertSame(42.5, PartCostPolicy::weightedAverage(0, 99.0, 5, 42.5));
        $this->assertSame(42.5, PartCostPolicy::weightedAverage(3, null, 5, 42.5));
    }

    public function test_multiple_entries_accumulate(): void
    {
        $cost = PartCostPolicy::weightedAverage(0, null, 10, 10.0);  // 10,00
        $cost = PartCostPolicy::weightedAverage(10, $cost, 10, 20.0); // 15,00
        $cost = PartCostPolicy::weightedAverage(20, $cost, 20, 30.0); // (300 + 600) / 40 = 22,50

        $this->assertSame(22.5, $cost);
    }

    public function test_movement_cost(): void
    {
        $this->assertSame(['unit_cost' => 12.5, 'total_cost' => 37.5], PartCostPolicy::movementCost(12.5, 3));
        $this->assertSame(['unit_cost' => null, 'total_cost' => null], PartCostPolicy::movementCost(null, 3));
    }
}
