<?php

namespace Tests\Unit;

use App\Services\OrderTotalsService;
use PHPUnit\Framework\TestCase;

class OrderServicesShareTest extends TestCase
{
    public function test_discount_and_surcharge_are_split_proportionally(): void
    {
        // Serviços 300 + peças 100: 75% do desconto e do acréscimo ficam no serviço.
        $share = OrderTotalsService::servicesShare(300, 100, 40, 20);

        $this->assertSame(['services_gross' => 300.0, 'services_discount' => 30.0, 'services_surcharge' => 15.0, 'services_net' => 285.0], $share);
    }

    public function test_rounding_keeps_the_exact_total(): void
    {
        // 1/3 de 10,00 não fecha em centavos: a parcela do serviço absorve a diferença.
        $share = OrderTotalsService::servicesShare(100, 50, 10, 0);
        $partsDiscount = round(10 * 50 / 150, 2);

        $this->assertSame(10.0, round($share['services_discount'] + $partsDiscount, 2));
        $this->assertSame(6.67, $share['services_discount']);
    }

    public function test_only_services_gets_everything(): void
    {
        $this->assertSame(80.0, OrderTotalsService::servicesShare(100, 0, 25, 5)['services_net']);
    }
}
