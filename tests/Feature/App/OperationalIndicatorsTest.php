<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderEvent;
use App\Models\App\OrderPayment;
use App\Models\App\OrderStatusHistory;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Intel\OperationalIndicatorsService;
use App\Services\OrderItemSyncService;
use App\Services\OrderTotalsService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VETOR-INTEL-04: regras documentadas em docs/architecture/vetor-intel-04-indicadores.md.
 */
class OperationalIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stalled_orders_use_status_events_then_history_and_count_unknown(): void
    {
        $stalled = $this->order(OrderStatus::REPAIR_IN_PROGRESS);
        $this->event($stalled, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::REPAIR_IN_PROGRESS, '2026-10-10 09:00:00');
        $recent = $this->order(OrderStatus::AWAITING_PART);
        $this->event($recent, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::AWAITING_PART, '2026-10-18 09:00:00');
        $legacy = $this->order(OrderStatus::OPEN);
        $history = OrderStatusHistory::create(['order_id' => $legacy->id, 'status' => 1, 'note' => 'Aberta']);
        DB::table('order_status_history')->where('id', $history->id)->update(['created_at' => '2026-10-01 09:00:00']);
        $this->order(OrderStatus::OPEN); // sem evento nem histórico
        $delivered = $this->order(OrderStatus::DELIVERED);
        $this->event($delivered, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::DELIVERED, '2026-09-01 09:00:00');

        $result = $this->service()->stalledOrders($this->tenant->id, 7);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['reference_unknown']);
        $this->assertSame([$legacy->id, $stalled->id], array_column($result['orders'], 'order_id'));
        $this->assertSame(['order_status_history', 'order_events'], array_column($result['orders'], 'reference'));
        $this->assertSame([19, 10], array_column($result['orders'], 'days'));
    }

    public function test_overdue_orders_and_renegotiated_deadlines(): void
    {
        $this->order(OrderStatus::OPEN, ['delivery_forecast' => '2026-10-15', 'original_delivery_forecast' => '2026-10-15']);
        $this->order(OrderStatus::REPAIR_IN_PROGRESS, ['delivery_forecast' => '2026-10-18', 'original_delivery_forecast' => '2026-10-12']);
        $this->order(OrderStatus::OPEN, ['delivery_forecast' => '2026-10-25']);
        $this->order(OrderStatus::DELIVERED, ['delivery_forecast' => '2026-10-01']);

        // Prazo vigente (regra da 1ª entrega) e, desde a 2ª entrega, contra o prazo original.
        $this->assertSame([
            'total' => 2,
            'renegotiated' => 1,
            'past_original' => 2,
            'past_original_renegotiated' => 1,
            'original_unknown' => 1,
        ], $this->service()->overdueOrders($this->tenant->id));
    }

    public function test_budgets_awaiting_and_expiring(): void
    {
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 300, sentAt: '2026-10-16 12:00:00', validUntil: '2026-10-22');
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 200, sentAt: null, legacy: true);
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 999, sentAt: '2026-10-01 12:00:00', validUntil: '2026-10-10');
        $this->budget($this->order(), 1, OrderBudget::STATUS_APPROVED, 500, sentAt: '2026-10-01 12:00:00');
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 50, sentAt: '2026-10-19 12:00:00', validUntil: '2026-10-30');

        $awaiting = $this->service()->budgetsAwaiting($this->tenant->id);
        $this->assertSame(3, $awaiting['count']);
        $this->assertSame(550.0, $awaiting['amount']);
        $this->assertSame(1, $awaiting['age_unknown']);
        $this->assertSame(2.5, $awaiting['average_age_days']); // (4 + 1) / 2

        $expiring = $this->service()->budgetsExpiring($this->tenant->id, 3);
        $this->assertSame(1, $expiring['count']);
        $this->assertSame(300.0, $expiring['amount']);
        $this->assertSame('2026-10-22', $expiring['budgets'][0]['valid_until']);
    }

    public function test_budget_conversion_counts_cycles_not_versions(): void
    {
        // Aprovada após renegociação: v1 enviada 10/10 10h, v2 aprovada 12/10 16h → 54 h.
        $approved = $this->order();
        $this->budget($approved, 1, OrderBudget::STATUS_SUPERSEDED, 300, sentAt: '2026-10-10 10:00:00');
        $this->budget($approved, 2, OrderBudget::STATUS_APPROVED, 280, sentAt: '2026-10-11 10:00:00', extra: ['approved_at' => '2026-10-12 16:00:00', 'responded_at' => '2026-10-12 16:00:00']);
        $this->budget($this->order(), 1, OrderBudget::STATUS_REJECTED, 100, sentAt: '2026-10-05 10:00:00', extra: ['rejected_at' => '2026-10-06 10:00:00', 'responded_at' => '2026-10-06 10:00:00']);
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 100, sentAt: '2026-10-05 10:00:00', validUntil: '2026-10-08');
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 100, sentAt: '2026-10-19 10:00:00');
        $this->budget($this->order(), 1, OrderBudget::STATUS_SENT, 100, sentAt: null, legacy: true);
        $this->budget($this->order(), 1, OrderBudget::STATUS_APPROVED, 100, sentAt: '2026-08-01 10:00:00', extra: ['approved_at' => '2026-08-02 10:00:00']);

        $result = $this->service()->budgetConversion($this->tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay());

        $this->assertSame(4, $result['cycles']);
        $this->assertSame([1, 1, 1, 1], [$result['approved'], $result['rejected'], $result['expired'], $result['pending']]);
        $this->assertSame(33.3, $result['conversion_rate']);
        $this->assertSame(54.0, $result['approval_time_hours']['average']);
        $this->assertSame(1.25, $result['average_versions_per_cycle']);
        $this->assertSame(1, $result['legacy_excluded']);
    }

    public function test_technician_productivity_uses_assignment_at_event_time(): void
    {
        $ana = User::factory()->forTenant($this->tenant->id)->create(['name' => 'Ana', 'roles' => User::ROLE_TECHNICIAN]);
        $bruno = User::factory()->forTenant($this->tenant->id)->create(['name' => 'Bruno', 'roles' => User::ROLE_TECHNICIAN]);
        $order = $this->order(OrderStatus::DELIVERED, ['user_id' => $bruno->id]);
        // Ana era a responsável quando concluiu; depois a OS foi transferida para Bruno.
        $this->assign($order, $ana, '2026-10-01 08:00:00', '2026-10-12 08:00:00');
        $this->assign($order, $bruno, '2026-10-12 08:00:00', null);
        $this->event($order, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::SERVICE_COMPLETED, '2026-10-11 15:00:00');
        $this->event($order, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::DELIVERED, '2026-10-13 15:00:00');
        $this->order(OrderStatus::OPEN, ['is_warranty_return' => true, 'warranty_source_order_id' => $order->id]);
        $unassigned = $this->order(OrderStatus::SERVICE_COMPLETED, ['user_id' => $ana->id]);
        $this->event($unassigned, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::SERVICE_COMPLETED, '2026-10-14 15:00:00');

        $result = $this->service()->technicianProductivity($this->tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay());

        $this->assertSame([
            ['technician_id' => $ana->id, 'name' => 'Ana', 'completed' => 1, 'delivered' => 0, 'warranty_returns' => 1],
            ['technician_id' => $bruno->id, 'name' => 'Bruno', 'completed' => 0, 'delivered' => 1, 'warranty_returns' => 0],
        ], $result['technicians']);
        // Sem histórico de atribuição: não é imputado ao técnico atual (Ana).
        $this->assertSame(['completed' => 1, 'delivered' => 0], $result['unattributed']);
    }

    public function test_deadline_compliance_against_original_promise(): void
    {
        $onTime = $this->order(OrderStatus::DELIVERED, ['original_delivery_forecast' => '2026-10-10', 'delivery_date' => '2026-10-09 17:00:00']);
        $late = $this->order(OrderStatus::DELIVERED, ['original_delivery_forecast' => '2026-10-10', 'delivery_date' => '2026-10-14 10:00:00']);
        $legacy = $this->order(OrderStatus::DELIVERED, ['original_delivery_forecast' => null, 'delivery_date' => '2026-10-11 10:00:00']);
        foreach ([[$onTime, '2026-10-09 17:00:00'], [$late, '2026-10-14 10:00:00'], [$legacy, '2026-10-11 10:00:00']] as [$order, $at]) {
            $this->event($order, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::DELIVERED, $at);
        }
        $this->event($late, OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED, null, '2026-10-08 10:00:00');

        $result = $this->service()->deadlineCompliance($this->tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay());

        $this->assertSame(3, $result['delivered']);
        $this->assertSame([1, 1], [$result['on_time'], $result['late']]);
        $this->assertSame(50.0, $result['on_time_rate']);
        $this->assertSame(4.0, $result['average_delay_days']);
        $this->assertSame(1, $result['renegotiated']);
        $this->assertSame(1, $result['original_unknown']);
    }

    public function test_profitability_sums_only_complete_orders(): void
    {
        $complete = $this->deliveredWithTotals(200, ['manual_parts_value' => 50, 'manual_parts_cost' => 30]);
        OrderPayment::query()->create(['order_id' => $complete->id, 'amount' => 250, 'payment_method' => 'pix', 'paid_at' => now(), 'fee_amount' => 5, 'fee_source' => 'manual', 'net_amount' => 245]);
        $incomplete = $this->deliveredWithTotals(400, ['manual_parts_value' => 100, 'manual_parts_cost' => null]);
        OrderPayment::query()->create(['order_id' => $incomplete->id, 'amount' => 500, 'payment_method' => 'pix', 'paid_at' => now()]);

        $result = $this->service()->profitability($this->tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay());

        $this->assertSame(2, $result['delivered']);
        $this->assertSame(1, $result['complete']);
        $this->assertSame(1, $result['incomplete']);
        $this->assertSame(250.0, $result['revenue_complete']);
        $this->assertSame(215.0, $result['margin_complete']); // 250 − 30 avulso − 5 taxa (sem comissão elegível)
        $this->assertSame(['manual_parts' => ['unknown' => 1], 'payment_fees' => ['unknown' => 1]], $result['incomplete_by_component']);
    }

    public function test_endpoint_returns_all_indicators_for_authorized_tenant_only(): void
    {
        $other = Tenant::factory()->create();
        Order::factory()->forTenant($other->id)->create(['service_status' => OrderStatus::OPEN, 'delivery_forecast' => '2026-10-01']);
        $this->order(OrderStatus::OPEN, ['delivery_forecast' => '2026-10-01']);

        $response = $this->getJson(route('app.intel.indicators', ['from' => '2026-10-01', 'to' => '2026-10-20']))->assertOk();

        $response->assertJsonStructure([
            'period', 'stalled_orders', 'overdue_orders', 'budgets_awaiting', 'budgets_expiring', 'budget_conversion',
            'technician_productivity', 'deadline_compliance', 'profitability', 'data_quality',
        ]);
        $this->assertSame(1, $response->json('overdue_orders.total'), 'OS de outro tenant não entra');
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-20'], $response->json('period'));
    }

    public function test_endpoint_permission_and_period_validation(): void
    {
        $this->getJson(route('app.intel.indicators', ['from' => '2025-01-01', 'to' => '2026-10-20']))->assertStatus(422);
        $this->getJson(route('app.intel.indicators', ['from' => '2026-10-20', 'to' => '2026-10-01']))->assertStatus(422);

        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician)->getJson(route('app.intel.indicators'))->assertForbidden();
    }

    private function service(): OperationalIndicatorsService
    {
        return app(OperationalIndicatorsService::class);
    }

    private function order(int $status = OrderStatus::BUDGET_GENERATED, array $attributes = []): Order
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
            'delivery_date' => null,
            'delivery_forecast' => '2026-10-30',
            'is_warranty_return' => false,
            ...$attributes,
        ]);
    }

    private function deliveredWithTotals(float $service, array $attributes): Order
    {
        $order = $this->order(OrderStatus::DELIVERED, ['service_value' => $service, 'services_performed' => 'Serviço', ...$attributes]);
        app(OrderItemSyncService::class)->sync($order);
        app(OrderTotalsService::class)->recalculate($order);
        $this->event($order, OrderEvent::TYPE_STATUS_CHANGED, OrderStatus::DELIVERED, '2026-10-15 10:00:00');

        return $order->fresh();
    }

    private function event(Order $order, string $type, ?int $toStatus, string $at): void
    {
        OrderEvent::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'event_type' => $type,
            'to_status' => $toStatus, 'actor_type' => 'user', 'actor_id' => $this->user->id, 'occurred_at' => $at,
        ]);
    }

    private function assign(Order $order, User $technician, string $from, ?string $to): void
    {
        OrderTechnicianAssignment::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'technician_id' => $technician->id,
            'assigned_by_type' => 'user', 'assigned_by' => $this->user->id, 'assigned_at' => $from,
            'unassigned_at' => $to, 'unassigned_by_type' => $to ? 'user' : null,
        ]);
    }

    private function budget(Order $order, int $version, string $status, float $amount, ?string $sentAt, ?string $validUntil = null, bool $legacy = false, array $extra = []): OrderBudget
    {
        return OrderBudget::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'version' => $version, 'status' => $status,
            'is_legacy' => $legacy, 'quoted_amount' => $amount, 'sent_at' => $sentAt, 'valid_until' => $validUntil,
            ...$extra,
        ]);
    }
}
