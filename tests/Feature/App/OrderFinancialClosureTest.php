<?php

namespace Tests\Feature\App;

use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use App\Services\OrderItemSyncService;
use App\Services\OrderTotalsService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class OrderFinancialClosureTest extends TestCase
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

    // ------------------------------------------------------------------ NFS-e

    public function test_nfse_without_adjustments_keeps_the_service_amount(): void
    {
        $payload = $this->builder()->serviceInvoice($this->orderWithTotals(300, discount: 0, surcharge: 0), $this->fiscalSetting(), 'int-1');

        $this->assertSame(300.0, $payload['total']['invoiceAmount']);
    }

    public function test_nfse_sends_service_share_of_discount_as_unconditioned_discount(): void
    {
        // VETOR-INTEL-02.1: antes bloqueado; agora usa total.discountUnconditionedAmount da API Spedy.
        $order = $this->orderWithTotals(300, discount: 30, surcharge: 0, manualParts: 100);

        $payload = $this->builder()->serviceInvoice($order, $this->fiscalSetting(), 'int-1');

        // Serviço 300 de 400: 75% do desconto de 30 = 22,50.
        $this->assertSame(300.0, $payload['total']['invoiceAmount']);
        $this->assertSame(22.5, $payload['total']['discountUnconditionedAmount']);
    }

    public function test_nfse_includes_service_share_of_surcharge_in_invoice_amount(): void
    {
        $order = $this->orderWithTotals(200, discount: 0, surcharge: 20);

        $payload = $this->builder()->serviceInvoice($order, $this->fiscalSetting(), 'int-1');

        $this->assertSame(220.0, $payload['total']['invoiceAmount']);
        $this->assertArrayNotHasKey('discountUnconditionedAmount', $payload['total']);
    }

    // --------------------------------------------------------------- taxas

    public function test_payment_freezes_fee_and_net_amount(): void
    {
        $order = $this->orderWithTotals(1000);
        $this->openCashSession();

        $this->post(route('app.orders.payments.store', $order), [
            'amount' => '1.000,00', 'fee_amount' => '30,00', 'payment_method' => 'cartao',
        ])->assertSessionHas('success');

        $payment = OrderPayment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('1000.00', $payment->amount);
        $this->assertSame('30.00', $payment->fee_amount);
        $this->assertSame('970.00', $payment->net_amount);
        $this->assertSame(OrderPayment::FEE_SOURCE_MANUAL, $payment->fee_source);
        $this->assertSame((float) $payment->amount - (float) $payment->fee_amount, (float) $payment->net_amount);
    }

    public function test_payment_without_informed_or_configured_fee_is_unknown(): void
    {
        // VETOR-INTEL-02.1: antes gravava taxa 0 "não informada"; zero sem comprovação é desconhecido.
        $order = $this->orderWithTotals(200);
        $this->openCashSession();

        $this->post(route('app.orders.payments.store', $order), ['amount' => '200,00', 'payment_method' => 'pix'])->assertSessionHas('success');

        $payment = OrderPayment::query()->where('order_id', $order->id)->sole();
        $this->assertNull($payment->fee_amount);
        $this->assertNull($payment->net_amount);
        $this->assertNull($payment->fee_source);
    }

    public function test_fee_greater_than_amount_is_refused(): void
    {
        $order = $this->orderWithTotals(100);
        $this->openCashSession();

        $this->post(route('app.orders.payments.store', $order), ['amount' => '100,00', 'fee_amount' => '150,00', 'payment_method' => 'cartao'])
            ->assertSessionHasErrors('fee_amount');

        $this->assertSame(0, OrderPayment::query()->where('order_id', $order->id)->count());
    }

    public function test_later_changes_do_not_rewrite_historical_fees(): void
    {
        $order = $this->orderWithTotals(1000);
        $this->openCashSession();
        $this->post(route('app.orders.payments.store', $order), ['amount' => '500,00', 'fee_amount' => '15,00', 'payment_method' => 'cartao']);
        // Pagamento anterior a esta etapa: taxa desconhecida.
        $legacy = OrderPayment::query()->create(['order_id' => $order->id, 'amount' => 100, 'payment_method' => 'cartao', 'paid_at' => now()]);

        // Novo salvamento da OS recalcula totais, recebíveis e comissão: pagamentos não mudam.
        $this->put(route('app.orders.update', $order), $this->orderPayload($order, ['service_value' => '1.200,00']))->assertSessionHasNoErrors();

        $payment = OrderPayment::query()->where('order_id', $order->id)->whereKeyNot($legacy->id)->sole();
        $this->assertSame('15.00', $payment->fee_amount);
        $this->assertSame('485.00', $payment->net_amount);
        $legacy->refresh();
        $this->assertNull($legacy->fee_amount);
        $this->assertNull($legacy->net_amount);
        $this->assertNull($legacy->fee_source);
    }

    // ------------------------------------------------------- exclusão da OS

    public function test_deleting_order_returns_stock_with_movement_and_cost(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create(['cost_price' => 40, 'sale_price' => 90, 'quantity' => 5]);
        $order = $this->order(OrderStatus::OPEN);
        $this->put(route('app.orders.update', $order), $this->orderPayload($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 2]]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, (int) $part->fresh()->quantity);

        $this->delete(route('app.orders.destroy', $order))->assertSessionHas('success');

        $this->assertNull(Order::query()->find($order->id));
        $this->assertSame(5, (int) $part->fresh()->quantity);
        $this->assertSame(0, DB::table('order_parts')->where('order_id', $order->id)->count());
        $movement = PartMovement::query()->where('part_id', $part->id)->where('movement_type', PartMovement::TYPE_RETURN)->sole();
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertSame(40.0, (float) $movement->unit_cost);
        $this->assertSame(80.0, (float) $movement->total_cost);
        $this->assertStringContainsString((string) $order->order_number, $movement->reason);
    }

    public function test_failure_during_deletion_rolls_back_stock_and_order(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create(['cost_price' => 40, 'sale_price' => 90, 'quantity' => 5]);
        $order = $this->order(OrderStatus::OPEN);
        $this->put(route('app.orders.update', $order), $this->orderPayload($order, ['allparts' => [['part_id' => $part->id, 'quantity' => 2]]]));

        Event::listen('eloquent.deleting: '.Order::class, fn () => throw new RuntimeException('falha simulada'));

        $this->delete(route('app.orders.destroy', $order));

        $this->assertNotNull(Order::query()->find($order->id));
        $this->assertSame(3, (int) $part->fresh()->quantity, 'estoque não pode ser devolvido se a OS não foi excluída');
        $this->assertSame(2, (int) DB::table('order_parts')->where('order_id', $order->id)->value('quantity'));
        $this->assertSame(0, PartMovement::query()->where('movement_type', PartMovement::TYPE_RETURN)->count());
    }

    private function builder(): SpedyPayloadBuilder
    {
        return app(SpedyPayloadBuilder::class);
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

    private function order(int $status, array $attributes = []): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'service_status' => $status,
            'service_value' => 0,
            'parts_value' => 0,
            'service_cost' => 0,
            'budget_description' => null,
            'budget_value' => 0,
            'delivery_date' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function orderPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_type' => Order::TYPE_EQUIPMENT,
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'model' => 'Modelo',
            'password' => null,
            'defect' => 'Defeito',
            'state_conservation' => 'Usado',
            'accessories' => 'Nenhum',
            'budget_value' => '0,00',
            'services_performed' => 'Troca de tela',
            'service_value' => number_format((float) $order->service_value, 2, ',', '.'),
            'manual_parts_value' => number_format((float) $order->manual_parts_value, 2, ',', '.'),
            'delivery_date' => null,
            'service_status' => (int) $order->service_status,
            'delivery_forecast' => $order->delivery_forecast,
            'observations' => null,
        ], $overrides);
    }

    private function openCashSession(): void
    {
        CashSession::create([
            'tenant_id' => $this->tenant->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'opening_balance' => 0, 'status' => 'open',
        ]);
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
