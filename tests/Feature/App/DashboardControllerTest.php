<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Message;
use App\Models\App\Order;
use App\Models\App\OrderLog;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\Sale;
use App\Models\App\SaleItem;
use App\Models\App\Schedule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Test Tenant']);
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        Other::factory()->forTenant($this->tenant->id)->create([
            'enable_finance' => true,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_dashboard_index_exposes_warranty_return_summary(): void
    {
        $warrantyReturnOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.acount.numorde_warranty_return', 1)
            ->assertViewHas('page.props.warrantyIndicator.warranty_return_threshold', 10.0)
            ->assertViewHas('page.props.orders.garantia', function (array $orders) use ($warrantyReturnOrder) {
                return collect($orders)->pluck('id')->contains($warrantyReturnOrder->id);
            });
    }

    public function test_dashboard_index_exposes_total_registration_counts_for_kpis(): void
    {
        Customer::factory()->forTenant($this->tenant->id)->count(3)->create([
            'created_at' => now()->subDays(30),
        ]);
        Order::factory()->forTenant($this->tenant->id)->count(2)->create([
            'created_at' => now()->subDays(30),
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->count(4)->create([
            'created_at' => now()->subDays(30),
        ]);
        Message::factory()->forTenant($this->tenant->id, $this->user->id, $this->user->id)->count(5)->create([
            'created_at' => now()->subDays(30),
        ]);
        Part::factory()->forTenant($this->tenant->id)->count(6)->create([
            'type' => 'part',
            'created_at' => now()->subDays(30),
        ]);
        Part::factory()->forTenant($this->tenant->id)->count(7)->create([
            'type' => 'product',
            'created_at' => now()->subDays(30),
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.acount.numcust', 3)
            ->assertViewHas('page.props.acount.numorde', 2)
            ->assertViewHas('page.props.acount.numshed', 4)
            ->assertViewHas('page.props.acount.nummess', 5)
            ->assertViewHas('page.props.acount.numparts', 6)
            ->assertViewHas('page.props.acount.numproducts', 7);
    }

    public function test_dashboard_index_exposes_operation_status_items_with_searchable_identifiers(): void
    {
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 1,
        ]);
        $budgetOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::BUDGET_GENERATED,
        ]);
        $deliveredOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays(8),
            'customer_feedback_submitted_at' => null,
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.feedbackDelay', 7)
            ->assertViewHas('page.props.orders.agendados', function ($items) use ($schedule) {
                return collect($items)->contains(fn ($item) => (int) $item['id'] === $schedule->id
                    && (int) $item['schedules_number'] === (int) $schedule->schedules_number);
            })
            ->assertViewHas('page.props.orders.gerados', function ($items) use ($budgetOrder) {
                return collect($items)->contains(fn ($item) => (int) $item['id'] === $budgetOrder->id
                    && (int) $item['order_number'] === (int) $budgetOrder->order_number);
            })
            ->assertViewHas('page.props.orders.feedback', function ($items) use ($deliveredOrder) {
                return collect($items)->contains(fn ($item) => (int) $item['id'] === $deliveredOrder->id
                    && (int) $item['order_number'] === (int) $deliveredOrder->order_number);
            });
    }

    public function test_dashboard_removes_delivered_orders_after_the_feedback_window_expires(): void
    {
        $visibleOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays(8),
            'customer_feedback_submitted_at' => null,
            'customer_feedback_request_expired_at' => null,
        ]);
        $expiredOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays(15),
            'customer_feedback_submitted_at' => null,
            'customer_feedback_request_expired_at' => null,
        ]);

        $this->get(route('app.dashboard'))
            ->assertOk()
            ->assertViewHas('page.props.orders.feedback', function ($items) use ($expiredOrder, $visibleOrder) {
                $ids = collect($items)->pluck('id');

                return $ids->contains($visibleOrder->id) && ! $ids->contains($expiredOrder->id);
            });
    }

    public function test_dashboard_feedback_metrics_use_submission_date_instead_of_order_creation_date(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'created_at' => now()->subMonths(3),
            'delivery_date' => now()->subMonths(2),
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 5,
        ]);

        $response = $this->get(route('app.metricsSystem', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('feedback_responses', 1)
            ->assertJsonPath('feedback_average_rating', 5);
    }

    public function test_schedules_index_filters_by_schedule_number(): void
    {
        $matchingSchedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'schedules_number' => 1234,
        ]);
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'schedules_number' => 4321,
        ]);

        $response = $this->get(route('app.schedules.index', ['search' => $matchingSchedule->schedules_number]));

        $response
            ->assertOk()
            ->assertViewHas('page.props.schedules.data', function ($items) use ($matchingSchedule) {
                return collect($items)->pluck('id')->all() === [$matchingSchedule->id];
            });
    }

    public function test_dashboard_index_exposes_commercial_performance_alert(): void
    {
        $budgetOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::BUDGET_GENERATED,
        ]);

        $paymentOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'service_cost' => 300,
        ]);

        OrderLog::query()->create([
            'order_id' => $budgetOrder->id,
            'action' => 'budget_follow_up_sent',
            'data' => ['trigger' => 'manual'],
            'created_at' => now()->subDay(),
        ]);

        OrderLog::query()->create([
            'order_id' => $paymentOrder->id,
            'action' => 'payment_reminder_sent',
            'data' => ['trigger' => 'automatic'],
            'created_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.performanceAlert.hasAlert', true)
            ->assertViewHas('page.props.performanceAlert.budgetBelowTarget', true)
            ->assertViewHas('page.props.performanceAlert.paymentBelowTarget', true);
    }

    public function test_dashboard_index_exposes_personal_task_indicator(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'budget_follow_up_assigned_to' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'updated_at' => now()->subDays(12),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'updated_at' => now()->subDays(12),
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.taskIndicator.hasTasks', true)
            ->assertViewHas('page.props.taskIndicator.total', 1)
            ->assertViewHas('page.props.taskIndicator.critical', 1)
            ->assertViewHas('page.props.taskIndicator.unassigned', 1);
    }

    public function test_metrics_system_returns_warranty_return_count_for_period(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
            'created_at' => now()->subDay(),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDay(),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
            'created_at' => now()->subDays(20),
        ]);

        $response = $this->get(route('app.metricsSystem', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('warranty_returns', 1)
            ->assertJsonPath('warranty_return_threshold', 10)
            ->assertJsonPath('warranty_return_rate', 50)
            ->assertJsonPath('warranty_return_alert', true);
    }

    public function test_metrics_system_returns_communication_follow_up_counts(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'service_cost' => 250,
            'delivery_date' => now()->subDays(4),
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);

        $response = $this->get(route('app.metricsSystem', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('budget_follow_ups', 1)
            ->assertJsonPath('pending_payment_follow_ups', 1);
    }

    public function test_metrics_system_returns_customer_feedback_summary(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'created_at' => now()->subDay(),
            'customer_feedback_submitted_at' => now()->subHours(6),
            'customer_feedback_rating' => 3,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.metricsSystem', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('feedback_responses', 1)
            ->assertJsonPath('feedback_average_rating', 3)
            ->assertJsonPath('feedback_response_rate', 50)
            ->assertJsonPath('low_feedbacks', 1)
            ->assertJsonPath('feedback_alert', true);
    }

    public function test_financial_order_kpis_include_previous_period_comparison(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDay(),
            'service_value' => 150,
            'parts_value' => 50,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays(8),
            'service_value' => 75,
            'parts_value' => 25,
        ]);

        $response = $this->get(route('app.kpisFinancialOrder', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('kpis.comparison.range_revenue.current', 200)
            ->assertJsonPath('kpis.comparison.range_revenue.previous', 100)
            ->assertJsonPath('kpis.comparison.range_revenue.change', 100)
            ->assertJsonPath('kpis.comparison.range_revenue.percent', 100);
    }

    public function test_schedule_kpis_include_standard_attendance_metrics(): void
    {
        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 3,
            'schedules' => now()->subDay(),
            'local_payment_received' => true,
            'local_payment_amount' => 120,
            'check_in_at' => now()->subDay()->setTime(10, 0),
            'check_out_at' => now()->subDay()->setTime(11, 30),
        ]);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 1,
            'schedules' => now()->subDay(),
        ]);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 2,
            'schedules' => now()->subDays(2),
        ]);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 3,
            'schedules' => now()->subDays(8),
        ]);

        $response = $this->get(route('app.kpisSchedules', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('kpis.total', 3)
            ->assertJsonPath('kpis.open', 1)
            ->assertJsonPath('kpis.in_progress', 1)
            ->assertJsonPath('kpis.completed', 1)
            ->assertJsonPath('kpis.overdue', 2)
            ->assertJsonPath('kpis.local_payment_total', 120)
            ->assertJsonPath('kpis.average_service_minutes', 90)
            ->assertJsonPath('kpis.completion_rate', 33.3)
            ->assertJsonPath('kpis.comparison.total.previous', 1);
    }

    public function test_equipment_chart_without_chart_equipments_returns_empty_lines_and_days(): void
    {
        // Sem equipamento marcado para o gráfico: a resposta é vazia, mas completa (VETOR-STABILIZE-02).
        Equipment::factory()->forTenant($this->tenant->id)->create(['chart' => false]);

        $response = $this->getJson(route('app.chartEquipments', ['timerange' => 7]))->assertOk();

        $this->assertSame([], $response->json('lines'));
        $this->assertNotEmpty($response->json('data'));
        $this->assertSame(['date'], array_keys($response->json('data.0')));
    }

    public function test_equipment_chart_returns_lines_and_daily_counts_for_chart_equipments(): void
    {
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create(['equipment' => 'Celular', 'chart' => true]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        Order::factory()->forTenant($this->tenant->id)->count(2)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'created_at' => now(),
        ]);

        $response = $this->getJson(route('app.chartEquipments', ['timerange' => 7]))->assertOk();
        $key = 'eq_'.$equipment->id;

        $this->assertSame([['key' => $key, 'label' => 'Celular']], $response->json('lines'));
        $this->assertSame(2, (int) collect($response->json('data'))->firstWhere('date', now()->toDateString())[$key]);
    }

    public function test_schedules_status_chart_returns_daily_status_series(): void
    {
        $scheduledAt = now()->subDay()->setTime(9, 0);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 1,
            'schedules' => $scheduledAt,
        ]);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 2,
            'schedules' => $scheduledAt->copy()->addHour(),
        ]);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'status' => 3,
            'schedules' => $scheduledAt->copy()->addHours(2),
        ]);

        $response = $this->get(route('app.schedulesStatusChart', ['timerange' => 7]));
        $point = collect($response->json())->firstWhere('date', $scheduledAt->format('Y-m-d'));

        $response->assertOk();
        $this->assertSame(3, $point['total']);
        $this->assertSame(1, $point['open']);
        $this->assertSame(1, $point['in_progress']);
        $this->assertSame(1, $point['completed']);
    }

    public function test_financial_sales_kpis_include_previous_period_comparison(): void
    {
        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'total_amount' => 300,
            'created_at' => now()->subDay(),
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'total_amount' => 100,
            'created_at' => now()->subDays(8),
        ]);

        $response = $this->get(route('app.kpisFinancialSales', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('kpis.comparison.range_revenue.current', 300)
            ->assertJsonPath('kpis.comparison.range_revenue.previous', 100)
            ->assertJsonPath('kpis.comparison.range_revenue.change', 200)
            ->assertJsonPath('kpis.comparison.range_revenue.percent', 200);
    }

    public function test_financial_sales_kpis_normalize_payment_methods_for_chart(): void
    {
        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'payment_method' => 'pix',
            'total_amount' => 120,
            'created_at' => now()->subDay(),
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 80,
            'created_at' => now()->subDay(),
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'payment_method' => 'credit_card',
            'total_amount' => 50,
            'created_at' => now()->subDay(),
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'payment_method' => 'vale',
            'total_amount' => 30,
            'created_at' => now()->subDay(),
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'cancelled',
            'payment_method' => 'pix',
            'total_amount' => 999,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.kpisFinancialSales', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('kpis.payment_methods.pix', 120)
            ->assertJsonPath('kpis.payment_methods.dinheiro', 80)
            ->assertJsonPath('kpis.payment_methods.cartao', 50)
            ->assertJsonPath('kpis.payment_methods.outros', 30);
    }

    public function test_financial_sales_kpis_include_actionable_sales_insights(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cabo USB-C',
        ]);

        $completedSale = Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'financial_status' => 'partial',
            'total_amount' => 300,
            'paid_amount' => 120,
            'created_at' => now()->subDay(),
        ]);

        SaleItem::factory()->create([
            'sale_id' => $completedSale->id,
            'part_id' => $part->id,
            'quantity' => 3,
            'unit_price' => 50,
        ]);

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'cancelled',
            'total_amount' => 70,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.kpisFinancialSales', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('kpis.pending_sales_amount', 180)
            ->assertJsonPath('kpis.cancelled_sales_amount', 70)
            ->assertJsonPath('kpis.cancelled_sales_count', 1)
            ->assertJsonPath('kpis.top_products.0.name', 'Cabo USB-C')
            ->assertJsonPath('kpis.top_products.0.quantity', 3)
            ->assertJsonPath('kpis.top_products.0.total', 150);
    }

    public function test_dashboard_index_exposes_customer_feedback_alert(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'customer_feedback_submitted_at' => now()->subDays(4),
            'customer_feedback_rating' => 2,
        ]);

        $response = $this->get(route('app.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.customerFeedbackAlert.hasAlert', true)
            ->assertViewHas('page.props.customerFeedbackAlert.total', 1)
            ->assertViewHas('page.props.customerFeedbackAlert.unassigned', 1)
            ->assertViewHas('page.props.customerFeedbackAlert.pending', 1)
            ->assertViewHas('page.props.customerFeedbackAlert.overdue', 1)
            ->assertViewHas('page.props.customerFeedbackAlert.slaDays', 3);
    }
}
