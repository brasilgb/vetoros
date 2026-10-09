<?php

namespace Tests\Feature\App;

use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\OperationalAudit;
use App\Models\App\Order;
use App\Models\App\OrderCommission;
use App\Models\App\OrderEvent;
use App\Models\App\OrderItem;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\SaleItem;
use App\Models\App\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\SaleService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderFinancialIntegrityTest extends TestCase
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

    // ------------------------------------------------------- custo e preço

    public function test_historical_cost_and_price_survive_catalog_changes_and_resave(): void
    {
        $part = $this->part(cost: 40, sale: 100);
        $order = $this->order();

        $this->save($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $part->forceFill(['cost_price' => 70, 'sale_price' => 180])->save();
        $this->save($order->fresh(), ['allparts' => [['part_id' => $part->id, 'quantity' => 1]], 'model' => 'Outro modelo']);

        $item = $this->stockItem($order, $part);
        $this->assertMoney(40, $item->unit_cost);
        $this->assertMoney(100, $item->unit_price);
        $this->assertMoney(100, $order->fresh()->parts_value);
        $this->assertNotNull($item->pricing_snapshot_at);
    }

    public function test_items_are_not_recreated_on_save(): void
    {
        $part = $this->part();
        $order = $this->order();
        $this->save($order, ['service_value' => '50,00', 'allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);
        $ids = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('id')->all();

        $this->save($order->fresh(), ['service_value' => '60,00', 'allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $this->assertSame($ids, OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('id')->all());
    }

    public function test_new_item_captures_cost_in_force_at_inclusion(): void
    {
        $first = $this->part(cost: 10, sale: 30);
        $second = $this->part(cost: 20, sale: 50);
        $order = $this->order();
        $this->save($order, ['allparts' => [['part_id' => $first->id, 'quantity' => 1]]]);

        $second->forceFill(['cost_price' => 25])->save();
        $this->save($order->fresh(), ['allparts' => [
            ['part_id' => $first->id, 'quantity' => 1],
            ['part_id' => $second->id, 'quantity' => 2],
        ]]);

        $this->assertMoney(25, $this->stockItem($order, $second)->unit_cost);
        $this->assertMoney(50, $this->stockItem($order, $second)->total_cost);
        $this->assertMoney(10, $this->stockItem($order, $first)->unit_cost);
    }

    public function test_quantity_change_preserves_frozen_cost_and_price(): void
    {
        $part = $this->part(cost: 40, sale: 100);
        $order = $this->order();
        $this->save($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $part->forceFill(['cost_price' => 55, 'sale_price' => 130])->save();
        $this->save($order->fresh(), ['allparts' => [['part_id' => $part->id, 'quantity' => 3]]]);

        $item = $this->stockItem($order, $part);
        $this->assertMoney(3, $item->quantity);
        $this->assertMoney(40, $item->unit_cost);
        $this->assertMoney(120, $item->total_cost);
        $this->assertMoney(300, $item->total_price);
    }

    public function test_removing_one_part_keeps_the_others_untouched(): void
    {
        $keep = $this->part(cost: 10, sale: 20);
        $remove = $this->part(cost: 30, sale: 60);
        $order = $this->order();
        $this->save($order, ['allparts' => [
            ['part_id' => $keep->id, 'quantity' => 1],
            ['part_id' => $remove->id, 'quantity' => 1],
        ]]);
        $kept = $this->stockItem($order, $keep);
        $keep->forceFill(['sale_price' => 999])->save();

        $this->post(route('app.orders.removePart'), ['order_id' => $order->id, 'part_id' => $remove->id])
            ->assertSessionHas('success');

        $this->assertNull(OrderItem::query()->where('order_id', $order->id)->where('source_id', $remove->id)->first());
        $after = $this->stockItem($order, $keep);
        $this->assertSame($kept->id, $after->id);
        $this->assertMoney(20, $after->unit_price);
        $this->assertMoney(20, $order->fresh()->parts_value);
        $this->assertSame(5, (int) $remove->fresh()->quantity);
    }

    public function test_legacy_item_is_not_restamped_with_current_cost(): void
    {
        $part = $this->part(cost: 40, sale: 100);
        $order = $this->order();
        $order->orderParts()->attach($part->id, ['quantity' => 1]);
        $legacy = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => OrderItem::TYPE_PRODUCT,
            'source_type' => OrderItem::SOURCE_PART,
            'source_id' => $part->id,
            'description' => $part->name,
            'quantity' => 1,
            'unit_price' => 90,
            'total_price' => 90,
            'unit_cost' => 35,
        ]);

        $part->forceFill(['cost_price' => 60])->save();
        $this->save($order->fresh(), ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $item = $this->stockItem($order, $part);
        $this->assertSame($legacy->id, $item->id);
        $this->assertMoney(35, $item->unit_cost);
        $this->assertNull($item->pricing_snapshot_at);
    }

    // ---------------------------------------------------------------- totais

    public function test_server_ignores_tampered_totals_and_applies_discount_and_surcharge(): void
    {
        $part = $this->part(cost: 40, sale: 100);
        $order = $this->order();

        $this->save($order, [
            'allparts' => [['part_id' => $part->id, 'quantity' => 2]],
            'service_value' => '150,00',
            'manual_parts_value' => '30,00',
            'discount_amount' => '25,00',
            'surcharge_amount' => '10,00',
            // adulterados no navegador
            'parts_value' => '1,00',
            'service_cost' => '1,00',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        // 150 + (2 × 100 + 30) + 10 − 25 = 365
        $this->assertMoney(230, $order->parts_value);
        $this->assertMoney(365, $order->service_cost);
        $this->assertSame(365.0, $order->total());
        $this->assertNotNull($order->totals_calculated_at);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'source_type' => OrderItem::SOURCE_MANUAL_PARTS, 'total_price' => 30]);
    }

    public function test_discount_cannot_make_total_negative(): void
    {
        $order = $this->order();

        $this->save($order, ['service_value' => '50,00', 'discount_amount' => '80,00'])
            ->assertSessionHasErrors('discount_amount');

        $this->assertSame(0, OrderItem::query()->where('order_id', $order->id)->count());
    }

    public function test_negative_money_is_rejected(): void
    {
        $this->save($this->order(), ['surcharge_amount' => '-5,00'])->assertSessionHasErrors('surcharge_amount');
    }

    public function test_legacy_client_without_manual_field_keeps_typed_parts_value_when_no_stock_parts(): void
    {
        $order = $this->order();

        $this->save($order, ['service_value' => '100,00', 'parts_value' => '50,00', 'service_cost' => '150,00'], ['manual_parts_value'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertMoney(50, $order->manual_parts_value);
        $this->assertMoney(150, $order->service_cost);
    }

    public function test_legacy_totals_decomposition_keeps_persisted_total_on_next_save(): void
    {
        $part = $this->part(cost: 30, sale: 80);
        // Legado: total gravado pelo navegador = 100 serviço + 130 "peças" (80 de estoque + 50 digitados) + 15 a mais.
        $order = $this->order(OrderStatus::OPEN, ['service_value' => 100, 'parts_value' => 130, 'service_cost' => 245]);
        $order->orderParts()->attach($part->id, ['quantity' => 1]);
        OrderItem::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'item_type' => OrderItem::TYPE_PRODUCT,
            'source_type' => OrderItem::SOURCE_PART, 'source_id' => $part->id, 'description' => $part->name,
            'quantity' => 1, 'unit_price' => 80, 'total_price' => 80, 'unit_cost' => 30,
        ]);

        $migration = require database_path('migrations/2026_10_08_100000_add_financial_integrity_fields.php');
        (new \ReflectionMethod($migration, 'decomposeLegacyOrderTotals'))->invoke($migration);

        $order->refresh();
        $this->assertMoney(50, $order->manual_parts_value);
        $this->assertMoney(15, $order->surcharge_amount);
        $this->assertMoney(0, $order->discount_amount);
        $this->assertNull($order->totals_calculated_at);

        // Primeiro salvamento após a implantação, pelo formulário novo: total idêntico.
        $this->save($order, [
            'allparts' => [['part_id' => $part->id, 'quantity' => 1]],
            'surcharge_amount' => '15,00',
            'discount_amount' => '0,00',
        ])->assertSessionHasNoErrors();

        $this->assertMoney(245, $order->fresh()->service_cost);
        $this->assertMoney(130, $order->fresh()->parts_value);
    }

    // ------------------------------------------------------- estoque e custo

    public function test_purchase_receipt_uses_weighted_average_and_records_movement_cost(): void
    {
        $part = $this->part(cost: 20, sale: 50, quantity: 10);

        $this->receivePurchase($part, 30, 40);

        // (10 × 20 + 30 × 40) / 40 = 35
        $part->refresh();
        $this->assertMoney(35, $part->cost_price);
        $this->assertSame(40, (int) $part->quantity);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'movement_type' => PartMovement::TYPE_PURCHASE,
            'unit_cost' => 40,
            'total_cost' => 1200,
        ]);
    }

    public function test_purchase_with_zero_stock_adopts_purchase_cost_then_averages(): void
    {
        $part = $this->part(cost: 99, sale: 150, quantity: 0);

        $this->receivePurchase($part, 10, 10);
        $this->assertMoney(10, $part->fresh()->cost_price);

        $this->receivePurchase($part, 10, 20);
        $this->assertMoney(15, $part->fresh()->cost_price);
    }

    public function test_order_use_and_return_movements_record_cost_without_changing_average(): void
    {
        $part = $this->part(cost: 40, sale: 100, quantity: 5);
        $order = $this->order();

        $this->save($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 2]]]);
        $this->save($order->fresh(), ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $this->assertDatabaseHas('part_movements', [
            'order_id' => $order->id, 'movement_type' => PartMovement::TYPE_ORDER_USE, 'quantity' => 2, 'unit_cost' => 40, 'total_cost' => 80,
        ]);
        $this->assertDatabaseHas('part_movements', [
            'order_id' => $order->id, 'movement_type' => PartMovement::TYPE_RETURN, 'quantity' => 1, 'unit_cost' => 40, 'total_cost' => 40,
        ]);
        $this->assertMoney(40, $part->fresh()->cost_price);
        $this->assertSame(4, (int) $part->fresh()->quantity);
    }

    public function test_sale_freezes_cost_and_cancellation_returns_at_same_cost(): void
    {
        $part = $this->part(cost: 12, sale: 30, quantity: 5);
        CashSession::create([
            'tenant_id' => $this->tenant->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'opening_balance' => 0, 'status' => 'open',
        ]);

        $sale = app(SaleService::class)->create([
            'payment_method' => 'pix', 'total_amount' => 60, 'parts' => [['part_id' => $part->id, 'quantity' => 2]],
        ]);
        $this->assertMoney(12, SaleItem::query()->where('sale_id', $sale->id)->value('unit_cost'));

        $part->forceFill(['cost_price' => 18])->save();
        app(SaleService::class)->cancel($sale, 'Cliente desistiu', $this->user);

        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id, 'movement_type' => PartMovement::TYPE_RETURN, 'unit_cost' => 12, 'total_cost' => 24,
        ]);
    }

    // -------------------------------------------------------------- prazos

    public function test_original_forecast_and_renegotiation_events(): void
    {
        $this->post(route('app.orders.store'), [
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Notebook',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'delivery_forecast' => '2026-10-10',
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame('2026-10-10', $order->original_delivery_forecast);
        $this->assertSame('2026-10-10', OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_ORDER_CREATED)->sole()->metadata['delivery_forecast']);

        $this->save($order, ['delivery_forecast' => '2026-10-15', 'delivery_forecast_reason' => 'Peça atrasou no fornecedor']);
        $this->save($order->fresh(), ['delivery_forecast' => '2026-10-15', 'model' => 'Sem mudança de prazo']);

        $order->refresh();
        $this->assertSame('2026-10-10', $order->original_delivery_forecast);
        $this->assertSame('2026-10-15', $order->delivery_forecast);

        $event = OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED)->sole();
        $this->assertSame('Peça atrasou no fornecedor', $event->reason);
        $this->assertSameMetadata(['previous' => '2026-10-10', 'new' => '2026-10-15', 'original' => '2026-10-10', 'original_known' => true], $event->metadata);
        $this->assertSame((int) $this->user->id, (int) $event->actor_id);
    }

    // ------------------------------------------------------------- entrega

    public function test_reopening_preserves_historical_delivery_and_second_delivery_is_distinct(): void
    {
        $order = $this->order(OrderStatus::CUSTOMER_NOTIFIED);

        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->save($order, ['service_status' => OrderStatus::DELIVERED])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01 10:00:00', $order->fresh()->delivery_date->toDateTimeString());

        Carbon::setTestNow('2026-10-03 09:00:00');
        $this->save($order->fresh(), [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
            'status_change_kind' => OrderStatus::KIND_REOPEN,
            'status_reason' => 'Defeito voltou',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01 10:00:00', $order->fresh()->delivery_date->toDateTimeString());

        Carbon::setTestNow('2026-10-05 16:00:00');
        $this->save($order->fresh(), ['service_status' => OrderStatus::DELIVERED])->assertSessionHasNoErrors();
        Carbon::setTestNow();

        $this->assertSame('2026-10-05 16:00:00', $order->fresh()->delivery_date->toDateTimeString());

        $deliveries = OrderEvent::query()->where('order_id', $order->id)->where('to_status', OrderStatus::DELIVERED)
            ->where('event_type', OrderEvent::TYPE_STATUS_CHANGED)->orderBy('id')->get();
        $this->assertSame(['2026-10-01 10:00:00', '2026-10-05 16:00:00'], $deliveries->map(fn ($e) => $e->metadata['delivered_at'])->all());

        $reopen = OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_ORDER_REOPENED)->sole();
        $this->assertSame('2026-10-01 10:00:00', $reopen->metadata['previous_delivery_date']);
    }

    public function test_correcting_delivery_date_while_delivered_creates_event(): void
    {
        $order = $this->order(OrderStatus::DELIVERED, ['delivery_date' => '2026-10-01 10:00:00']);

        $this->save($order, ['service_status' => OrderStatus::DELIVERED, 'delivery_date' => '2026-09-30 17:00:00'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30 17:00:00', $order->fresh()->delivery_date->toDateTimeString());
        $event = OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_DELIVERY_DATE_CHANGED)->sole();
        $this->assertSameMetadata(['previous' => '2026-10-01 10:00:00', 'new' => '2026-09-30 17:00:00'], $event->metadata);
    }

    // ------------------------------------------------------------- comissão

    public function test_consolidated_commission_is_not_recalculated(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN, 'status' => 1, 'commission_percentage' => 10,
        ]);
        $other = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN, 'status' => 1, 'commission_percentage' => 50,
        ]);
        $order = $this->order(OrderStatus::CUSTOMER_NOTIFIED, ['user_id' => $technician->id]);

        $this->save($order, ['user_id' => $technician->id, 'service_value' => '200,00', 'service_status' => OrderStatus::DELIVERED]);
        $commission = OrderCommission::query()->where('order_id', $order->id)->sole();
        $this->assertMoney(20, $commission->commission_amount);

        $technician->forceFill(['commission_percentage' => 30])->save();
        $this->save($order->fresh(), ['user_id' => $other->id, 'service_value' => '500,00', 'service_status' => OrderStatus::DELIVERED]);

        $commission->refresh();
        $this->assertSame($technician->id, (int) $commission->user_id);
        $this->assertMoney(10, $commission->commission_percentage);
        $this->assertMoney(200, $commission->base_amount);
        $this->assertMoney(20, $commission->commission_amount);
    }

    // ------------------------------------------------------------ segurança

    public function test_password_never_reaches_operational_audits(): void
    {
        $order = $this->order(OrderStatus::OPEN);

        $this->save($order, ['password' => 'senha-do-aparelho', 'service_status' => OrderStatus::IN_DIAGNOSIS])
            ->assertSessionHasNoErrors();

        $audits = OperationalAudit::query()->where('entity_id', $order->id)->get();
        $this->assertNotEmpty($audits);
        $this->assertStringNotContainsString('senha-do-aparelho', $audits->pluck('data')->toJson());
        $this->assertStringNotContainsString('"password"', $audits->pluck('data')->toJson());
    }

    // --------------------------------------------------------- multitenancy

    public function test_items_movements_and_deadline_events_are_isolated_by_tenant(): void
    {
        $part = $this->part();
        $order = $this->order();
        $this->save($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 1]], 'delivery_forecast' => now()->addDays(20)->toDateString()]);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('part_movements', ['order_id' => $order->id, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'tenant_id' => $this->tenant->id, 'event_type' => OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED]);

        $otherTenant = Tenant::factory()->create();
        $this->withSession(['tenant_id' => $otherTenant->id])->actingAs(User::factory()->forTenant($otherTenant->id)->create());

        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, PartMovement::query()->count());
        $this->assertSame(0, OrderEvent::query()->where('event_type', OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED)->count());
    }

    public function test_part_from_another_tenant_cannot_be_used_in_order(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignPart = Part::factory()->forTenant($foreignTenant->id)->create(['quantity' => 5]);
        $order = $this->order();

        $this->save($order, ['allparts' => [['part_id' => $foreignPart->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('allparts');

        $this->assertSame(5, (int) $foreignPart->fresh()->quantity);
    }

    private function assertMoney(float $expected, mixed $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame(round($expected, 2), round((float) $actual, 2));
    }

    private function receivePurchase(Part $part, int $quantity, float $unitCost): void
    {
        $service = app(PurchaseOrderService::class);
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Fornecedor']);

        $purchase = $service->create(['supplier_id' => $supplier->id, 'items' => [
            ['part_id' => $part->id, 'quantity' => $quantity, 'unit_cost' => $unitCost],
        ]], $this->user->id);
        $service->send($purchase, $this->user->id);
        $service->receive($purchase->fresh(), $this->user->id);
    }

    private function part(float $cost = 10, float $sale = 25, int $quantity = 5): Part
    {
        return Part::factory()->forTenant($this->tenant->id)->create([
            'cost_price' => $cost,
            'sale_price' => $sale,
            'quantity' => $quantity,
        ]);
    }

    private function order(int $status = OrderStatus::OPEN, array $attributes = []): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'service_status' => $status,
            'service_value' => 0,
            'parts_value' => 0,
            'service_cost' => 0,
            'delivery_date' => null,
            'warranty_days' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function stockItem(Order $order, Part $part): OrderItem
    {
        return OrderItem::query()->where('order_id', $order->id)
            ->where('source_type', OrderItem::SOURCE_PART)->where('source_id', $part->id)->sole();
    }

    /**
     * @param  list<string>  $without  campos omitidos (simula cliente antigo)
     */
    private function save(Order $order, array $overrides = [], array $without = [])
    {
        return $this->put(route('app.orders.update', $order), array_diff_key(array_merge([
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
            'service_value' => number_format((float) $order->service_value, 2, ',', '.'),
            'manual_parts_value' => number_format((float) $order->manual_parts_value, 2, ',', '.'),
            'delivery_date' => null,
            'service_status' => (int) $order->service_status,
            'delivery_forecast' => $order->delivery_forecast,
            'observations' => null,
        ], $overrides), array_flip($without)));
    }

    /**
     * Compara metadados de evento com valores e tipos estritos, ignorando só a ordem das
     * chaves: a coluna JSON do MySQL normaliza a ordem; o SQLite preserva a de gravação.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $actual
     */
    private function assertSameMetadata(array $expected, ?array $actual): void
    {
        $this->assertIsArray($actual);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }
}
