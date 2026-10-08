<?php

namespace Tests\Feature\App;

use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderMessage;
use App\Models\App\OrderPayment;
use App\Models\App\OrderStatusHistory;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\PaymentFeeSetting;
use App\Models\App\WhatsappConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderCommunicationContextService;
use App\Services\OrderItemSyncService;
use App\Services\OrderMarginService;
use App\Services\OrderMessageService;
use App\Services\OrderTotalsService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * VETOR-INTEL-03 (revisão e homologação): regressões dos defeitos corrigidos nesta etapa
 * e acesso cruzado entre tenants nos fluxos do VETOR-INTEL.
 */
class IntelHomologationTest extends TestCase
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
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '(51) 99999-9999', 'email' => null]);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    // ============================================ filtros de orçamento

    public function test_budget_follow_up_uses_budget_sent_at_not_order_updated_at(): void
    {
        // Enviado há 10 dias, mas a OS foi editada hoje (updated_at recente): continua pendente.
        $stale = $this->orderInBudget(sentDaysAgo: 10, updatedDaysAgo: 0);
        // Enviado hoje, mas updated_at antigo: ainda não é caso de acompanhamento.
        $fresh = $this->orderInBudget(sentDaysAgo: 0, updatedDaysAgo: 30);

        $service = app(OrderCommunicationContextService::class);
        $this->assertTrue($service->isBudgetFollowUp($stale, $this->tenant->id));
        $this->assertFalse($service->isBudgetFollowUp($fresh, $this->tenant->id));
        $this->assertSame(10, $service->communicationDaysPending($stale));

        $this->get(route('app.orders.index', ['filter' => 'budget_follow_up']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $stale->id));

        $this->assertSame(1, Order::query()->whereBudgetPendingBefore(now()->subDays(2))->count());
    }

    public function test_legacy_budget_without_sent_at_uses_status_history_then_updated_at(): void
    {
        $withHistory = $this->order(OrderStatus::BUDGET_GENERATED);
        DB::table('orders')->where('id', $withHistory->id)->update(['updated_at' => now()]);
        $history = OrderStatusHistory::create(['order_id' => $withHistory->id, 'status' => OrderStatus::BUDGET_GENERATED, 'note' => 'Orçamento Gerado']);
        DB::table('order_status_history')->where('id', $history->id)->update(['created_at' => now()->subDays(8)]);

        $noEvidence = $this->order(OrderStatus::BUDGET_GENERATED);
        DB::table('orders')->where('id', $noEvidence->id)->update(['updated_at' => now()->subDays(6)]);

        $this->assertSame(8, (int) $withHistory->fresh()->budgetPendingSince()->diffInDays(now()));
        // Sem nenhuma evidência: mantém a referência anterior (updated_at), não inventa data.
        $this->assertSame(6, (int) $noEvidence->fresh()->budgetPendingSince()->diffInDays(now()));
    }

    public function test_answered_budget_is_not_pending(): void
    {
        $order = $this->orderInBudget(sentDaysAgo: 10, updatedDaysAgo: 10);
        OrderBudget::query()->where('order_id', $order->id)->update(['status' => OrderBudget::STATUS_SUPERSEDED]);
        $history = OrderStatusHistory::create(['order_id' => $order->id, 'status' => OrderStatus::BUDGET_GENERATED, 'note' => 'x']);
        DB::table('order_status_history')->where('id', $history->id)->update(['created_at' => now()->subDay()]);

        // Sem versão "sent" vale a entrada no status (ontem), não o envio antigo superado.
        $this->assertSame(1, (int) $order->fresh()->budgetPendingSince()->diffInDays(now()));
    }

    // ============================================== ACK entre engines WAHA

    public function test_noweb_ack_with_serialized_id_matches_short_stored_id(): void
    {
        $message = $this->message('3EB0C7A1B2C3D4E5');

        $updated = app(OrderMessageService::class)->applyWahaAck("vetoros1-{$this->tenant->id}", 'true_5551999999999@c.us_3EB0C7A1B2C3D4E5', 2);

        $this->assertSame($message->id, $updated?->id);
        $this->assertSame(OrderMessage::STATUS_DELIVERED, $message->fresh()->status);
    }

    public function test_short_ack_id_matches_serialized_stored_id(): void
    {
        $message = $this->message('true_5551999999999@c.us_ABCDEF123');

        app(OrderMessageService::class)->applyWahaAck("vetoros1-{$this->tenant->id}", 'ABCDEF123', 3);

        $this->assertSame(OrderMessage::STATUS_READ, $message->fresh()->status);
    }

    public function test_ack_does_not_match_partial_or_other_tenant_ids(): void
    {
        $message = $this->message('true_5551999999999@c.us_XYZ999');
        $other = Tenant::factory()->create();
        WhatsappConnection::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'session_name' => "vetoros1-{$other->id}", 'status' => 'connected']);

        $service = app(OrderMessageService::class);
        $this->assertNull($service->applyWahaAck("vetoros1-{$this->tenant->id}", 'Z999', 3), 'sufixo parcial não casa');
        $this->assertNull($service->applyWahaAck("vetoros1-{$other->id}", 'true_5551999999999@c.us_XYZ999', 3), 'outro tenant não casa');
        $this->assertSame(OrderMessage::STATUS_SENT, $message->fresh()->status);
    }

    // ================================================================ margem

    public function test_fees_are_pending_while_order_is_not_fully_paid(): void
    {
        PaymentFeeSetting::query()->create(['tenant_id' => $this->tenant->id, 'payment_method' => 'pix', 'fee_percentage' => 1, 'fee_fixed_amount' => 0]);
        $order = $this->orderWithTotals(200);
        $this->openCashSession();
        $this->post(route('app.orders.payments.store', $order), ['amount' => '100,00', 'payment_method' => 'pix'])->assertSessionHas('success');

        $partial = app(OrderMarginService::class)->breakdown($order->fresh());
        $this->assertSame(OrderMarginService::PENDING, $partial['costs']['payment_fees']['status']);
        $this->assertNull($partial['margin']);

        $this->post(route('app.orders.payments.store', $order), ['amount' => '100,00', 'payment_method' => 'pix']);
        $paid = app(OrderMarginService::class)->breakdown($order->fresh());
        $this->assertSame(OrderMarginService::KNOWN, $paid['costs']['payment_fees']['status']);
        $this->assertSame(2.0, $paid['costs']['payment_fees']['amount']);
    }

    public function test_commission_is_pending_when_delivered_by_customer_and_not_consolidated(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN, 'status' => 1, 'commission_percentage' => 10]);
        $order = $this->orderWithTotals(100, ['service_status' => OrderStatus::CUSTOMER_NOTIFIED, 'user_id' => $technician->id]);
        // A retirada pública exige saldo quitado.
        OrderPayment::query()->create(['order_id' => $order->id, 'amount' => 100, 'payment_method' => 'pix', 'paid_at' => now()]);
        Auth::logout();

        $this->post(route('orders.pickup.acknowledge', $order->tracking_token))->assertSessionHas('success');

        $margin = app(OrderMarginService::class)->breakdown($order->fresh());
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->fresh()->service_status);
        $this->assertSame(OrderMarginService::PENDING, $margin['costs']['commission']['status']);
        $this->assertNull($margin['margin']);
    }

    public function test_delivered_without_eligible_technician_has_known_zero_commission(): void
    {
        $order = $this->orderWithTotals(100, ['service_status' => OrderStatus::DELIVERED]);

        $commission = app(OrderMarginService::class)->breakdown($order)['costs']['commission'];

        $this->assertSame(['amount' => 0.0, 'status' => OrderMarginService::KNOWN], $commission);
    }

    // ============================================== isolamento entre tenants

    public function test_cross_tenant_access_is_blocked_on_intel_flows(): void
    {
        $other = Tenant::factory()->create();
        $foreignOrder = Order::factory()->forTenant($other->id)->create(['service_status' => OrderStatus::OPEN, 'service_cost' => 100, 'service_value' => 100]);
        $foreignPayment = OrderPayment::query()->create(['order_id' => $foreignOrder->id, 'amount' => 50, 'payment_method' => 'pix', 'paid_at' => now()]);
        $foreignPart = Part::factory()->forTenant($other->id)->create(['quantity' => 4]);
        DB::table('order_parts')->insert(['order_id' => $foreignOrder->id, 'part_id' => $foreignPart->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->openCashSession();
        Http::fake();

        // Visualizar, pagar, remover pagamento/peça, enviar WhatsApp e excluir a OS de outro tenant.
        $this->get(route('app.orders.show', $foreignOrder))->assertSessionMissing('success');
        $this->post(route('app.orders.payments.store', $foreignOrder), ['amount' => '10,00', 'payment_method' => 'pix', 'fee_amount' => '1,00']);
        $this->delete(route('app.orders.payments.destroy', [$foreignOrder, $foreignPayment]));
        $this->post(route('app.orders.removePart'), ['order_id' => $foreignOrder->id, 'part_id' => $foreignPart->id]);
        $this->post(route('app.orders.whatsapp.send', $foreignOrder), ['message' => 'oi', 'template' => 'defaultmessage']);
        $this->delete(route('app.orders.destroy', $foreignOrder));

        $this->assertNotNull(Order::withoutGlobalScopes()->find($foreignOrder->id));
        $this->assertSame(1, OrderPayment::query()->where('order_id', $foreignOrder->id)->count());
        $this->assertNotNull(OrderPayment::query()->find($foreignPayment->id));
        $this->assertSame(4, (int) Part::withoutGlobalScopes()->find($foreignPart->id)->quantity);
        $this->assertSame(1, DB::table('order_parts')->where('order_id', $foreignOrder->id)->count());
        $this->assertSame(0, OrderMessage::withoutGlobalScopes()->count());
        Http::assertNothingSent();

        // Consultas do tenant atual não enxergam nada do outro.
        $this->assertSame(0, Order::query()->whereKey($foreignOrder->id)->count());
        $this->assertSame(0, Part::query()->whereKey($foreignPart->id)->count());
    }

    // ============================================================= helpers

    private function orderInBudget(int $sentDaysAgo, int $updatedDaysAgo): Order
    {
        $order = $this->order(OrderStatus::BUDGET_GENERATED);
        OrderBudget::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'version' => 1,
            'status' => OrderBudget::STATUS_SENT, 'quoted_amount' => 100, 'sent_at' => now()->subDays($sentDaysAgo),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => now()->subDays($updatedDaysAgo)]);

        return $order->fresh();
    }

    private function message(string $providerId): OrderMessage
    {
        WhatsappConnection::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id],
            ['session_name' => "vetoros1-{$this->tenant->id}", 'status' => 'connected'],
        );
        $order = $this->order(OrderStatus::OPEN);

        return OrderMessage::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'channel' => 'whatsapp', 'provider' => 'waha',
            'provider_message_id' => $providerId, 'status' => OrderMessage::STATUS_SENT, 'sent_at' => now(),
        ]);
    }

    private function orderWithTotals(float $service, array $attributes = []): Order
    {
        $order = $this->order(OrderStatus::SERVICE_COMPLETED, ['service_value' => $service, 'services_performed' => 'Serviço', ...$attributes]);
        app(OrderItemSyncService::class)->sync($order);
        app(OrderTotalsService::class)->recalculate($order);

        return $order->fresh();
    }

    private function openCashSession(): void
    {
        CashSession::create(['tenant_id' => $this->tenant->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'opening_balance' => 0, 'status' => 'open']);
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
            'manual_parts_value' => 0,
            'budget_value' => 0,
            'delivery_date' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }
}
