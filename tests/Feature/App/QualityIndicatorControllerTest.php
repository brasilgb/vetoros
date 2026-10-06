<?php

namespace Tests\Feature\App;

use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\App\OrderLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityIndicatorControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Test Tenant']);
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_quality_indicators_page_is_accessible(): void
    {
        $response = $this->get(route('app.quality.index'));

        $response->assertOk();
    }

    public function test_quality_metrics_endpoint_returns_quality_summary(): void
    {
        Other::query()->create([
            'warranty_return_alert_threshold' => 8,
        ]);

        $sourceOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'created_at' => now()->subDays(20),
            'delivery_date' => now()->subDays(12),
            'warranty_expires_at' => now()->addDays(10),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
            'warranty_source_order_id' => $sourceOrder->id,
            'customer_id' => $sourceOrder->customer_id,
            'equipment_id' => $sourceOrder->equipment_id,
            'user_id' => $sourceOrder->user_id,
            'created_at' => now()->subDay(),
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDay(),
            'service_status' => OrderStatus::DELIVERED,
            'customer_feedback_submitted_at' => now()->subHours(2),
            'customer_feedback_rating' => 4,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
            'created_at' => now()->subDays(8),
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDays(8),
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDays(9),
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        $response = $this->get(route('app.quality.metrics', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.warranty_returns', 1)
            ->assertJsonPath('summary.warranty_return_threshold', 8)
            ->assertJsonPath('summary.warranty_return_rate', 50)
            ->assertJsonPath('summary.severity', 'Critico')
            ->assertJsonPath('summary.feedback_responses', 1)
            ->assertJsonPath('summary.feedback_average_rating', 4)
            ->assertJsonPath('summary.feedback_response_rate', 100)
            ->assertJsonPath('summary.low_feedbacks', 0)
            ->assertJsonPath('feedback_orders.data.0.rating', 4)
            ->assertJsonPath('trend.granularity', 'daily')
            ->assertJsonPath('comparison.previous_warranty_return_rate', 33.3)
            ->assertJsonPath('comparison.delta_rate', 16.7)
            ->assertJsonPath('comparison.direction', 'piorou');
    }

    public function test_quality_metrics_endpoint_returns_low_feedback_recovery_list(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => \App\Support\OrderStatus::DELIVERED,
            'created_at' => now()->subDay(),
            'customer_feedback_submitted_at' => now()->subHours(3),
            'customer_feedback_rating' => 2,
            'customer_feedback_comment' => 'Demorou mais do que eu esperava.',
        ]);

        $response = $this->get(route('app.quality.metrics', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.low_feedbacks', 1)
            ->assertJsonPath('low_feedback_orders.0.rating', 2)
            ->assertJsonPath('low_feedback_orders.0.comment', 'Demorou mais do que eu esperava.');
    }

    public function test_quality_metrics_include_recent_feedback_from_an_older_order(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'created_at' => now()->subMonths(3),
            'delivery_date' => now()->subMonths(2),
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 2,
            'customer_feedback_comment' => 'A comunicação poderia ser melhor.',
        ]);

        $response = $this->get(route('app.quality.metrics', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.feedback_responses', 1)
            ->assertJsonPath('summary.feedback_average_rating', 2)
            ->assertJsonPath('summary.low_feedbacks', 1)
            ->assertJsonPath('feedback_orders.data.0.comment', 'A comunicação poderia ser melhor.')
            ->assertJsonPath('low_feedback_orders.0.comment', 'A comunicação poderia ser melhor.');
    }

    public function test_quality_metrics_only_expose_feedbacks_from_the_authenticated_company(): void
    {
        $visibleOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 5,
            'customer_feedback_comment' => 'Atendimento excelente.',
        ]);

        $otherTenant = Tenant::factory()->create();
        Order::factory()->forTenant($otherTenant->id)->create([
            'service_status' => OrderStatus::DELIVERED,
            'customer_feedback_submitted_at' => now()->subMinutes(30),
            'customer_feedback_rating' => 1,
            'customer_feedback_comment' => 'Avaliação de outra empresa.',
        ]);

        $response = $this->get(route('app.quality.metrics', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'feedback_orders.data')
            ->assertJsonPath('feedback_orders.data.0.id', $visibleOrder->id)
            ->assertJsonMissing(['comment' => 'Avaliação de outra empresa.']);
    }

    public function test_quality_feedback_table_is_paginated_on_the_server(): void
    {
        Order::factory()->forTenant($this->tenant->id)->count(25)->create([
            'service_status' => OrderStatus::DELIVERED,
            'created_at' => now()->subDay(),
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 5,
        ]);

        $response = $this->get(route('app.quality.metrics', [
            'timerange' => 7,
            'feedback_page' => 2,
            'feedback_per_page' => 20,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('feedback_orders.current_page', 2)
            ->assertJsonPath('feedback_orders.per_page', 20)
            ->assertJsonPath('feedback_orders.total', 25)
            ->assertJsonCount(5, 'feedback_orders.data');
    }

    public function test_quality_feedback_recovery_can_be_updated(): void
    {
        $assignee = User::factory()->forTenant($this->tenant->id)->create();

        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => \App\Support\OrderStatus::DELIVERED,
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 2,
            'customer_feedback_comment' => 'Não gostei do prazo.',
        ]);

        $response = $this->post(route('app.quality.feedback-recovery', $order), [
            'assigned_to' => $assignee->id,
            'status' => 'in_progress',
            'notes' => 'Contato realizado para entender o atraso.',
        ]);

        $response->assertSessionHas('success', 'Tratativa da avaliação atualizada com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_feedback_recovery_assigned_to' => $assignee->id,
            'customer_feedback_recovery_status' => 'in_progress',
            'customer_feedback_recovery_notes' => 'Contato realizado para entender o atraso.',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_feedback_recovery_updated',
        ]);
    }

    public function test_quality_metrics_can_filter_low_feedback_queue(): void
    {
        $assignee = User::factory()->forTenant($this->tenant->id)->create();

        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => \App\Support\OrderStatus::DELIVERED,
            'created_at' => now()->subHour(),
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 2,
            'customer_feedback_recovery_status' => 'pending',
        ]);

        $target = Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => \App\Support\OrderStatus::DELIVERED,
            'created_at' => now()->subHour(),
            'customer_feedback_submitted_at' => now()->subHour(),
            'customer_feedback_rating' => 3,
            'customer_feedback_recovery_status' => 'in_progress',
            'customer_feedback_recovery_assigned_to' => $assignee->id,
        ]);

        $response = $this->get(route('app.quality.metrics', [
            'timerange' => 7,
            'recovery_status' => 'in_progress',
            'assigned_to' => (string) $assignee->id,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.low_feedbacks', 2)
            ->assertJsonPath('summary.unassigned_low_feedbacks', 1)
            ->assertJsonPath('summary.recovery_pending', 1)
            ->assertJsonPath('summary.recovery_in_progress', 1)
            ->assertJsonPath('low_feedback_orders.0.id', $target->id);
    }

    public function test_quality_metrics_reports_overdue_low_feedback_cases(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'service_status' => \App\Support\OrderStatus::DELIVERED,
            'created_at' => now()->subDays(2),
            'customer_feedback_submitted_at' => now()->subDays(4),
            'customer_feedback_rating' => 2,
            'customer_feedback_recovery_status' => 'pending',
        ]);

        $response = $this->get(route('app.quality.metrics', ['timerange' => 7]));

        $response
            ->assertOk()
            ->assertJsonPath('summary.recovery_overdue', 1)
            ->assertJsonPath('summary.recovery_sla_days', 3)
            ->assertJsonPath('low_feedback_orders.0.recovery_overdue', true);
    }
}
