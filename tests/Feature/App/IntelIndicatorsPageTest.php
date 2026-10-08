<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderEvent;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * VETOR-INTEL-04 (2ª entrega): tela Indicadores, dados para a tela e alinhamento do dashboard.
 */
class IntelIndicatorsPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Other::factory()->forTenant($this->tenant->id)->create();
        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_browser_request_renders_page_with_default_filters(): void
    {
        $this->get(route('app.intel.indicators'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('app/intel/indicators')
                ->where('defaults.from', '2026-09-21')
                ->where('defaults.to', '2026-10-20')
                ->where('defaults.stalled_days', 7)
                ->where('defaults.expiring_days', 3)
                ->where('defaults.max_period_days', 366));
    }

    public function test_json_request_still_returns_indicators(): void
    {
        $this->getJson(route('app.intel.indicators'))->assertOk()->assertJsonStructure(['period', 'stalled_orders', 'data_quality']);
    }

    public function test_page_requires_reports_permission(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician);

        $page = $this->get(route('app.intel.indicators'));
        $this->assertNotSame(200, $page->getStatusCode());
        $this->getJson(route('app.intel.indicators'))->assertForbidden();
    }

    public function test_filters_change_period_and_thresholds(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $this->event($order, OrderStatus::OPEN, '2026-10-15 10:00:00');

        // Parada há 5 dias: aparece com limite 3, não com o padrão 7.
        $this->assertSame(0, $this->getJson(route('app.intel.indicators'))->json('stalled_orders.total'));
        $this->assertSame(1, $this->getJson(route('app.intel.indicators', ['stalled_days' => 3]))->json('stalled_orders.total'));

        $response = $this->getJson(route('app.intel.indicators', ['from' => '2026-10-01', 'to' => '2026-10-10', 'expiring_days' => 10]));
        $response->assertJsonPath('period', ['from' => '2026-10-01', 'to' => '2026-10-10']);
        $response->assertJsonPath('budgets_expiring.within_days', 10);
        $this->getJson(route('app.intel.indicators', ['stalled_days' => 0]))->assertStatus(422);
    }

    public function test_empty_dataset_reports_unknown_instead_of_zero(): void
    {
        $data = $this->getJson(route('app.intel.indicators'))->assertOk()->json();

        // Sem dados: taxas e médias são null ("não foi possível calcular"), contagens são zero reais.
        $this->assertNull($data['budget_conversion']['conversion_rate']);
        $this->assertNull($data['budget_conversion']['approval_time_hours']['average']);
        $this->assertNull($data['deadline_compliance']['on_time_rate']);
        $this->assertNull($data['budgets_awaiting']['average_age_days']);
        $this->assertNull($data['profitability']['margin_rate_complete']);
        $this->assertSame(0, $data['profitability']['complete']);
        $this->assertSame(0, $data['stalled_orders']['total']);
        $this->assertSame([], array_filter($data['data_quality']));
    }

    public function test_partial_data_is_flagged_in_data_quality(): void
    {
        $this->order(OrderStatus::OPEN, ['original_delivery_forecast' => null]); // sem histórico e sem prazo original
        $delivered = $this->order(OrderStatus::DELIVERED, ['original_delivery_forecast' => null, 'delivery_date' => '2026-10-10 10:00:00']);
        $this->event($delivered, OrderStatus::DELIVERED, '2026-10-10 10:00:00');

        $quality = $this->getJson(route('app.intel.indicators'))->json('data_quality');

        $this->assertSame(1, $quality['stalled_reference_unknown']);
        $this->assertSame(1, $quality['active_original_unknown']);
        $this->assertSame(1, $quality['deadline_original_unknown']);
    }

    public function test_stalled_orders_carry_context_and_link_target_without_retroactive_technician(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['name' => 'Maria Cliente']);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create(['equipment' => 'Notebook']);
        $technician = User::factory()->forTenant($this->tenant->id)->create(['name' => 'Téc. Ana', 'roles' => User::ROLE_TECHNICIAN]);

        $withHistory = $this->order(OrderStatus::REPAIR_IN_PROGRESS, ['customer_id' => $customer->id, 'equipment_id' => $equipment->id]);
        $this->event($withHistory, OrderStatus::REPAIR_IN_PROGRESS, '2026-10-01 10:00:00');
        OrderTechnicianAssignment::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $withHistory->id, 'technician_id' => $technician->id,
            'assigned_by_type' => 'user', 'assigned_by' => $this->user->id, 'assigned_at' => '2026-10-01 09:00:00',
        ]);
        // Técnico atual sem trilha de atribuição: não é exibido como responsável.
        $legacy = $this->order(OrderStatus::OPEN, ['user_id' => $technician->id]);
        $this->event($legacy, OrderStatus::OPEN, '2026-10-02 10:00:00');

        $orders = collect($this->getJson(route('app.intel.indicators'))->json('stalled_orders.orders'))->keyBy('order_id');

        $this->assertSame('Maria Cliente', $orders[$withHistory->id]['customer']);
        $this->assertSame('Notebook', $orders[$withHistory->id]['equipment']);
        $this->assertSame('Téc. Ana', $orders[$withHistory->id]['technician']);
        $this->assertNull($orders[$legacy->id]['technician']);
        // O link da tela aponta para a OS e ela abre para o usuário.
        $this->get(route('app.orders.show', $withHistory->id))->assertOk();
    }

    public function test_page_data_is_isolated_by_tenant(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Order::factory()->forTenant($other->id)->create(['service_status' => OrderStatus::OPEN, 'original_delivery_forecast' => null]);
        OrderEvent::create([
            'tenant_id' => $other->id, 'order_id' => $foreign->id, 'event_type' => OrderEvent::TYPE_STATUS_CHANGED,
            'to_status' => OrderStatus::OPEN, 'actor_type' => 'system', 'occurred_at' => '2026-09-01 10:00:00',
        ]);

        $data = $this->getJson(route('app.intel.indicators', ['stalled_days' => 1]))->json();

        $this->assertSame(0, $data['stalled_orders']['total']);
        $this->assertSame(0, $data['data_quality']['active_original_unknown']);
    }

    public function test_dashboard_awaiting_approval_excludes_expired_budget(): void
    {
        $valid = $this->order(OrderStatus::BUDGET_GENERATED);
        OrderBudget::create(['tenant_id' => $this->tenant->id, 'order_id' => $valid->id, 'version' => 1, 'status' => 'sent', 'sent_at' => now(), 'valid_until' => '2026-10-25']);
        $expired = $this->order(OrderStatus::BUDGET_GENERATED);
        OrderBudget::create(['tenant_id' => $this->tenant->id, 'order_id' => $expired->id, 'version' => 1, 'status' => 'sent', 'sent_at' => now()->subDays(10), 'valid_until' => '2026-10-15']);
        $superseded = $this->order(OrderStatus::BUDGET_GENERATED);
        // Só a versão corrente conta: v1 vencida substituída por v2 válida.
        OrderBudget::create(['tenant_id' => $this->tenant->id, 'order_id' => $superseded->id, 'version' => 1, 'status' => 'expired', 'sent_at' => now()->subDays(10)]);
        OrderBudget::create(['tenant_id' => $this->tenant->id, 'order_id' => $superseded->id, 'version' => 2, 'status' => 'sent', 'sent_at' => now()]);

        $this->get(route('app.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('acount.numorde_awaiting_approval', 2)
            ->where('acount.numorde_budget_expired', 1));

        $this->assertSame(2, $this->getJson(route('app.intel.indicators'))->json('budgets_awaiting.count'), 'dashboard e indicadores concordam');
    }

    private function order(int $status, array $attributes = []): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($this->tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($this->tenant->id)->create()->id,
            'user_id' => null,
            'service_status' => $status,
            'delivery_date' => null,
            'delivery_forecast' => '2026-10-30',
            'original_delivery_forecast' => '2026-10-30',
            ...$attributes,
        ]);
    }

    private function event(Order $order, int $toStatus, string $at): void
    {
        OrderEvent::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'event_type' => OrderEvent::TYPE_STATUS_CHANGED,
            'to_status' => $toStatus, 'actor_type' => 'user', 'actor_id' => $this->user->id, 'occurred_at' => $at,
        ]);
    }
}
