<?php

namespace Tests\Feature\App;

use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\OrderBudgetItem;
use App\Models\App\OrderCommission;
use App\Models\App\OrderItem;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\PaymentFeeSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use App\Services\OrderItemSyncService;
use App\Services\OrderMarginService;
use App\Services\OrderTotalsService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * VETOR-INTEL-02.1: taxas de pagamento, custo de materiais avulsos, exclusão de OS,
 * NFS-e com desconto/acréscimo e fonte de margem.
 */
class FinancialIntegrityClosureTest extends TestCase
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
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['cpfcnpj' => '529.982.247-25', 'email' => null]);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    // ================================================================ taxas

    public function test_percentage_fee_from_configuration(): void
    {
        $this->configureFee('cartao', percentage: 2.99);
        $order = $this->orderWithTotals(1000);

        $this->pay($order, '1.000,00', 'cartao');

        $payment = $this->lastPayment($order);
        $this->assertSame(OrderPayment::FEE_SOURCE_CONFIG, $payment->fee_source);
        $this->assertMoney(29.90, $payment->fee_amount);
        $this->assertMoney(970.10, $payment->net_amount);
        $this->assertSame(2.99, round((float) $payment->fee_percentage, 3));
        $this->assertMoney(0, $payment->fee_fixed_amount);
    }

    public function test_fixed_plus_percentage_fee_and_cap_at_amount(): void
    {
        $this->configureFee('boleto', percentage: 1, fixed: 3.49);
        $order = $this->orderWithTotals(500);

        $this->pay($order, '200,00', 'boleto');
        $this->pay($order, '2,00', 'boleto');

        [$first, $second] = OrderPayment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertMoney(5.49, $first->fee_amount);   // 200 × 1% + 3,49
        $this->assertMoney(194.51, $first->net_amount);
        $this->assertMoney(2.00, $second->fee_amount);  // taxa limitada ao valor pago
        $this->assertMoney(0, $second->net_amount);
    }

    public function test_configured_zero_fee_is_known_zero(): void
    {
        $this->configureFee('dinheiro', percentage: 0, fixed: 0);
        $order = $this->orderWithTotals(100);

        $this->pay($order, '100,00', 'dinheiro');

        $payment = $this->lastPayment($order);
        $this->assertSame(OrderPayment::FEE_SOURCE_CONFIG, $payment->fee_source);
        $this->assertMoney(0, $payment->fee_amount);
        $this->assertMoney(100, $payment->net_amount);
    }

    public function test_manual_fee_overrides_configuration(): void
    {
        $this->configureFee('cartao', percentage: 5);
        $order = $this->orderWithTotals(1000);

        $this->pay($order, '1.000,00', 'cartao', '0,00');

        $payment = $this->lastPayment($order);
        $this->assertSame(OrderPayment::FEE_SOURCE_MANUAL, $payment->fee_source);
        $this->assertMoney(0, $payment->fee_amount);
    }

    public function test_fee_is_frozen_after_configuration_changes(): void
    {
        $this->configureFee('cartao', percentage: 3);
        $order = $this->orderWithTotals(1000);
        $this->pay($order, '400,00', 'cartao');

        $this->put(route('app.payment-fee-settings.update'), ['fees' => [
            ['payment_method' => 'cartao', 'fee_percentage' => 10, 'fee_fixed_amount' => 1],
        ]])->assertSessionHas('success');
        $this->pay($order, '600,00', 'cartao');

        [$partial1, $partial2] = OrderPayment::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertMoney(12, $partial1->fee_amount);   // 3% de 400, congelado
        $this->assertMoney(388, $partial1->net_amount);
        $this->assertSame(3.0, round((float) $partial1->fee_percentage, 3));
        $this->assertMoney(61, $partial2->fee_amount);   // 10% de 600 + 1
        $this->assertMoney(539, $partial2->net_amount);
    }

    public function test_fee_setting_can_be_removed_back_to_unknown(): void
    {
        $this->configureFee('pix', percentage: 1);

        $this->put(route('app.payment-fee-settings.update'), ['fees' => [
            ['payment_method' => 'pix', 'fee_percentage' => null, 'fee_fixed_amount' => null],
        ]])->assertSessionHas('success');

        $this->assertSame(0, PaymentFeeSetting::query()->count());
        $order = $this->orderWithTotals(100);
        $this->pay($order, '100,00', 'pix');
        $this->assertNull($this->lastPayment($order)->fee_amount);
    }

    public function test_fee_setting_rejects_invalid_values(): void
    {
        $this->put(route('app.payment-fee-settings.update'), ['fees' => [
            ['payment_method' => 'cartao', 'fee_percentage' => -1],
            ['payment_method' => 'cheque', 'fee_percentage' => 1],
        ]])->assertSessionHasErrors(['fees.0.fee_percentage', 'fees.1.payment_method']);

        $this->assertSame(0, PaymentFeeSetting::query()->count());
    }

    public function test_legacy_payment_keeps_unknown_fee(): void
    {
        $order = $this->orderWithTotals(300);
        $legacy = OrderPayment::query()->create(['order_id' => $order->id, 'amount' => 300, 'payment_method' => 'cartao', 'paid_at' => now()]);
        $this->configureFee('cartao', percentage: 4);

        // Configurar taxa depois não estima taxa para o pagamento antigo.
        $this->put(route('app.orders.update', $order), $this->payload($order))->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertNull($legacy->fee_amount);
        $this->assertNull($legacy->net_amount);
        $this->assertNull($legacy->fee_source);
    }

    public function test_fee_configuration_is_isolated_by_tenant(): void
    {
        $other = Tenant::factory()->create();
        PaymentFeeSetting::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'payment_method' => 'cartao', 'fee_percentage' => 50, 'fee_fixed_amount' => 0]);
        $order = $this->orderWithTotals(100);

        $this->pay($order, '100,00', 'cartao');

        $this->assertNull($this->lastPayment($order)->fee_amount, 'taxa de outro tenant não pode ser aplicada');
        $this->assertSame(0, PaymentFeeSetting::query()->count());

        // Salvar a configuração grava só no próprio tenant; a do outro fica intacta.
        $this->put(route('app.payment-fee-settings.update'), ['fees' => [['payment_method' => 'cartao', 'fee_percentage' => 1]]]);
        $this->assertSame(50.0, round((float) PaymentFeeSetting::withoutGlobalScopes()->where('tenant_id', $other->id)->value('fee_percentage'), 3));
        $this->assertSame(1, PaymentFeeSetting::query()->count());
    }

    public function test_technician_cannot_change_fee_settings(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician);

        $this->put(route('app.payment-fee-settings.update'), ['fees' => [['payment_method' => 'cartao', 'fee_percentage' => 1]]]);

        $this->assertSame(0, PaymentFeeSetting::withoutGlobalScopes()->count());
    }

    // ===================================================== materiais avulsos

    public function test_manual_parts_cost_is_stored_separately_from_revenue(): void
    {
        $order = $this->order();

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'service_value' => '100,00', 'manual_parts_value' => '300,00', 'manual_parts_cost' => '220,00',
        ]))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertMoney(300, $order->manual_parts_value);
        $this->assertMoney(220, $order->manual_parts_cost);
        $this->assertMoney(400, $order->service_cost);   // receita não muda com o custo
        $item = OrderItem::query()->where('order_id', $order->id)->where('source_type', OrderItem::SOURCE_MANUAL_PARTS)->sole();
        $this->assertMoney(300, $item->total_price);
        $this->assertMoney(220, $item->total_cost);
    }

    public function test_manual_parts_zero_and_unknown_cost_are_distinct(): void
    {
        $zero = $this->order();
        $unknown = $this->order();

        $this->put(route('app.orders.update', $zero), $this->payload($zero, ['manual_parts_value' => '50,00', 'manual_parts_cost' => '0,00']));
        $this->put(route('app.orders.update', $unknown), $this->payload($unknown, ['manual_parts_value' => '50,00', 'manual_parts_cost' => '']));

        $this->assertMoney(0, $zero->fresh()->manual_parts_cost);
        $this->assertNull($unknown->fresh()->manual_parts_cost);
        $this->assertNull(OrderItem::query()->where('order_id', $unknown->id)->where('source_type', OrderItem::SOURCE_MANUAL_PARTS)->sole()->total_cost);
    }

    public function test_negative_manual_cost_is_rejected(): void
    {
        $order = $this->order();

        $this->put(route('app.orders.update', $order), $this->payload($order, ['manual_parts_value' => '50,00', 'manual_parts_cost' => '-10,00']))
            ->assertSessionHasErrors('manual_parts_cost');

        $this->assertNull($order->fresh()->manual_parts_cost);
    }

    public function test_old_client_without_cost_field_keeps_stored_cost_and_legacy_stays_unknown(): void
    {
        $order = $this->order();
        $this->put(route('app.orders.update', $order), $this->payload($order, ['manual_parts_value' => '80,00', 'manual_parts_cost' => '60,00']));

        $payload = $this->payload($order->fresh(), ['manual_parts_value' => '80,00']);
        unset($payload['manual_parts_cost']);
        $this->put(route('app.orders.update', $order), $payload)->assertSessionHasNoErrors();
        $this->assertMoney(60, $order->fresh()->manual_parts_cost);

        $legacy = $this->order(attributes: ['manual_parts_value' => 70]);
        $legacyPayload = $this->payload($legacy, ['manual_parts_value' => '70,00']);
        unset($legacyPayload['manual_parts_cost']);
        $this->put(route('app.orders.update', $legacy), $legacyPayload)->assertSessionHasNoErrors();
        $this->assertNull($legacy->fresh()->manual_parts_cost);
    }

    public function test_later_cost_change_does_not_rewrite_sent_budget_snapshot(): void
    {
        $order = $this->order();
        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'manual_parts_value' => '300,00', 'manual_parts_cost' => '220,00',
            'budget_description' => 'Troca', 'budget_value' => '300,00', 'service_status' => OrderStatus::BUDGET_GENERATED,
        ]))->assertSessionHasNoErrors();

        $this->put(route('app.orders.update', $order), $this->payload($order->fresh(), [
            'manual_parts_value' => '300,00', 'manual_parts_cost' => '250,00',
            'budget_description' => 'Troca', 'budget_value' => '300,00',
        ]))->assertSessionHasNoErrors();

        $snapshot = OrderBudgetItem::query()->where('source_type', OrderItem::SOURCE_MANUAL_PARTS)->sole();
        $this->assertMoney(220, $snapshot->total_cost);
        $this->assertMoney(250, $order->fresh()->manual_parts_cost);
    }

    public function test_manual_cost_of_other_tenant_order_cannot_be_changed(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Order::factory()->forTenant($other->id)->create(['manual_parts_value' => 10, 'manual_parts_cost' => 5]);

        $this->put(route('app.orders.update', $foreign), $this->payload($foreign, ['manual_parts_cost' => '1,00']));

        $this->assertMoney(5, Order::withoutGlobalScopes()->find($foreign->id)->manual_parts_cost);
    }

    // ======================================================= exclusão da OS

    public function test_deleting_order_with_multiple_parts_returns_exact_quantities(): void
    {
        $screen = $this->part(cost: 40, quantity: 5);
        $battery = $this->part(cost: 25.5, quantity: 3);
        $order = $this->order();
        $this->put(route('app.orders.update', $order), $this->payload($order, ['allparts' => [
            ['part_id' => $screen->id, 'quantity' => 2],
            ['part_id' => $battery->id, 'quantity' => 3],
        ]]))->assertSessionHasNoErrors();

        $this->delete(route('app.orders.destroy', $order))->assertSessionHas('success');

        $this->assertSame(5, (int) $screen->fresh()->quantity);
        $this->assertSame(3, (int) $battery->fresh()->quantity);
        $returns = PartMovement::query()->where('movement_type', PartMovement::TYPE_RETURN)->get()->keyBy('part_id');
        $this->assertSame(2, (int) $returns[$screen->id]->quantity);
        $this->assertMoney(80, $returns[$screen->id]->total_cost);
        $this->assertSame(3, (int) $returns[$battery->id]->quantity);
        $this->assertMoney(76.5, $returns[$battery->id]->total_cost);
    }

    public function test_deleting_order_without_parts_creates_no_movement(): void
    {
        $order = $this->order();

        $this->delete(route('app.orders.destroy', $order))->assertSessionHas('success');

        $this->assertNull(Order::query()->find($order->id));
        $this->assertSame(0, PartMovement::query()->count());
    }

    public function test_deletion_rollback_and_other_tenant_untouched(): void
    {
        $other = Tenant::factory()->create();
        $foreignPart = Part::factory()->forTenant($other->id)->create(['quantity' => 7]);
        $part = $this->part(cost: 10, quantity: 4);
        $order = $this->order();
        $this->put(route('app.orders.update', $order), $this->payload($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]));

        Event::listen('eloquent.deleting: '.Order::class, fn () => throw new RuntimeException('falha simulada'));
        $this->delete(route('app.orders.destroy', $order));

        $this->assertNotNull(Order::query()->find($order->id));
        $this->assertSame(3, (int) $part->fresh()->quantity);
        $this->assertSame(7, (int) Part::withoutGlobalScopes()->find($foreignPart->id)->quantity);
        $this->assertSame(0, PartMovement::withoutGlobalScopes()->where('movement_type', PartMovement::TYPE_RETURN)->count());
    }

    // ================================================================ NFS-e

    /**
     * @return array<string, array{float, float, float, float, float, float}>
     */
    public static function fiscalCases(): array
    {
        // serviço, peças avulsas, desconto, acréscimo, invoiceAmount esperado, desconto esperado
        return [
            'sem ajustes' => [300, 0, 0, 0, 300, 0],
            'desconto só serviço' => [300, 0, 30, 0, 300, 30],
            'acréscimo só serviço' => [300, 0, 0, 15, 315, 0],
            'desconto + acréscimo com peças' => [300, 100, 40, 20, 315, 30],
            'arredondamento 1/3' => [100, 50, 10, 10, 106.67, 6.67],
        ];
    }

    /**
     * @dataProvider fiscalCases
     */
    public function test_nfse_amount_reconciles_with_order_service_share(float $service, float $manual, float $discount, float $surcharge, float $expectedInvoice, float $expectedDiscount): void
    {
        $order = $this->orderWithTotals($service, $discount, $surcharge, $manual);

        $total = app(SpedyPayloadBuilder::class)->serviceInvoice($order, $this->fiscalSetting(), 'int-1')['total'];

        $this->assertSame($expectedInvoice, $total['invoiceAmount']);
        $this->assertSame($expectedDiscount, $total['discountUnconditionedAmount'] ?? 0.0);

        // Valor fiscal final == parcela dos serviços no valor financeiro da OS.
        $fiscalNet = round($total['invoiceAmount'] - ($total['discountUnconditionedAmount'] ?? 0), 2);
        $share = OrderTotalsService::servicesShare($service, $manual, $discount, $surcharge);
        $this->assertSame($share['services_net'], $fiscalNet);

        // Sem peças, a NFS-e representa a cobrança inteira: fiscal == total da OS.
        if ($manual === 0.0) {
            $this->assertSame($order->total(), $fiscalNet);
        }

        // Nenhuma regressão: chaves do payload existente preservadas.
        $this->assertArrayHasKey('issRate', $total);
    }

    public function test_nfse_blocks_when_discount_consumes_the_whole_service(): void
    {
        $order = $this->orderWithTotals(100, discount: 100);

        $this->expectException(FiscalValidationException::class);
        app(SpedyPayloadBuilder::class)->serviceInvoice($order, $this->fiscalSetting(), 'int-1');
    }

    // ================================================================ margem

    public function test_margin_is_computed_only_when_every_cost_is_known(): void
    {
        $part = $this->part(cost: 40, sale: 100, quantity: 5);
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN, 'status' => 1, 'commission_percentage' => 10]);
        $this->configureFee('cartao', percentage: 2);
        $order = $this->order(OrderStatus::CUSTOMER_NOTIFIED, ['user_id' => $technician->id]);

        $this->put(route('app.orders.update', $order), $this->payload($order, [
            'user_id' => $technician->id,
            'allparts' => [['part_id' => $part->id, 'quantity' => 1]],
            'service_value' => '200,00', 'manual_parts_value' => '50,00', 'manual_parts_cost' => '30,00',
            'service_status' => OrderStatus::DELIVERED,
        ]))->assertSessionHasNoErrors();
        $this->pay($order->fresh(), '350,00', 'cartao');

        $margin = app(OrderMarginService::class)->breakdown($order->fresh());
        // Receita 350; custos: peça 40 + avulso 30 + comissão 20 (10% de 200) + taxa 7 (2% de 350).
        $this->assertTrue($margin['complete']);
        $this->assertSame(253.0, $margin['margin']);
        $this->assertMoney(20, OrderCommission::query()->sole()->commission_amount);

        // Custo avulso desconhecido: a margem deixa de ser calculada (sem número enganoso).
        $order->fresh()->forceFill(['manual_parts_cost' => null])->save();
        $unknown = app(OrderMarginService::class)->breakdown($order->fresh());
        $this->assertFalse($unknown['complete']);
        $this->assertNull($unknown['margin']);
        $this->assertSame(OrderMarginService::UNKNOWN, $unknown['costs']['manual_parts']['status']);
    }

    public function test_margin_marks_unknown_fee_and_pending_commission(): void
    {
        $order = $this->orderWithTotals(100);
        OrderPayment::query()->create(['order_id' => $order->id, 'amount' => 100, 'payment_method' => 'pix', 'paid_at' => now()]);

        $margin = app(OrderMarginService::class)->breakdown($order);

        $this->assertSame(OrderMarginService::UNKNOWN, $margin['costs']['payment_fees']['status']);
        $this->assertSame(OrderMarginService::PENDING, $margin['costs']['commission']['status']);
        $this->assertNull($margin['margin']);
    }

    // ============================================================= helpers

    private function configureFee(string $method, float $percentage = 0, float $fixed = 0): void
    {
        PaymentFeeSetting::query()->create([
            'tenant_id' => $this->tenant->id, 'payment_method' => $method, 'fee_percentage' => $percentage, 'fee_fixed_amount' => $fixed,
        ]);
    }

    private function pay(Order $order, string $amount, string $method, ?string $fee = null): void
    {
        if (! CashSession::query()->where('status', 'open')->exists()) {
            CashSession::create([
                'tenant_id' => $this->tenant->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'opening_balance' => 0, 'status' => 'open',
            ]);
        }

        $this->post(route('app.orders.payments.store', $order), array_filter([
            'amount' => $amount, 'payment_method' => $method, 'fee_amount' => $fee,
        ], fn ($value) => $value !== null))->assertSessionHas('success');
    }

    private function lastPayment(Order $order): OrderPayment
    {
        return OrderPayment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    private function assertMoney(float $expected, mixed $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame(round($expected, 2), round((float) $actual, 2));
    }

    private function part(float $cost = 10, float $sale = 25, int $quantity = 5): Part
    {
        return Part::factory()->forTenant($this->tenant->id)->create(['cost_price' => $cost, 'sale_price' => $sale, 'quantity' => $quantity]);
    }

    private function orderWithTotals(float $service, float $discount = 0, float $surcharge = 0, float $manualParts = 0): Order
    {
        $order = $this->order(OrderStatus::SERVICE_COMPLETED, [
            'service_value' => $service,
            'services_performed' => 'Troca de tela',
            'manual_parts_value' => $manualParts,
            'discount_amount' => $discount,
            'surcharge_amount' => $surcharge,
        ]);
        app(OrderItemSyncService::class)->sync($order);
        app(OrderTotalsService::class)->recalculate($order);

        return $order->fresh();
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
            'manual_parts_value' => 0,
            'manual_parts_cost' => null,
            'budget_description' => null,
            'budget_value' => 0,
            'delivery_date' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_type' => Order::TYPE_EQUIPMENT,
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $order->user_id,
            'model' => 'Modelo',
            'password' => null,
            'defect' => 'Defeito',
            'state_conservation' => 'Usado',
            'accessories' => 'Nenhum',
            'budget_description' => $order->budget_description,
            'budget_value' => number_format((float) $order->budget_value, 2, ',', '.'),
            'services_performed' => 'Troca de tela',
            'service_value' => number_format((float) $order->service_value, 2, ',', '.'),
            'manual_parts_value' => number_format((float) $order->manual_parts_value, 2, ',', '.'),
            'manual_parts_cost' => $order->manual_parts_cost === null ? '' : number_format((float) $order->manual_parts_cost, 2, ',', '.'),
            'delivery_date' => null,
            'service_status' => (int) $order->service_status,
            'delivery_forecast' => $order->delivery_forecast,
            'observations' => null,
        ], $overrides);
    }

    private function fiscalSetting(): FiscalSetting
    {
        return FiscalSetting::query()->updateOrCreate(['tenant_id' => $this->tenant->id], [
            'enabled' => true,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'service_list_item' => '14.01',
            'service_city_code' => '4314902',
            'default_iss_rate' => 2.5,
            'nfse_taxation_type' => 'taxationInMunicipality',
        ]);
    }
}
