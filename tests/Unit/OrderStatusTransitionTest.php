<?php

namespace Tests\Unit;

use App\Support\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    public function test_normal_forward_flow_needs_no_reason(): void
    {
        $flow = [
            [OrderStatus::OPEN, OrderStatus::IN_DIAGNOSIS],
            [OrderStatus::IN_DIAGNOSIS, OrderStatus::BUDGET_GENERATED],
            [OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED],
            [OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS],
            [OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::SERVICE_COMPLETED],
            [OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED],
            [OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::DELIVERED],
            // atalhos usados hoje no balcão
            [OrderStatus::OPEN, OrderStatus::DELIVERED],
            [OrderStatus::OPEN, OrderStatus::BUDGET_APPROVED],
            [OrderStatus::BUDGET_REJECTED, OrderStatus::CUSTOMER_NOTIFIED],
            // cliente muda de decisão sobre o orçamento
            [OrderStatus::BUDGET_REJECTED, OrderStatus::BUDGET_APPROVED],
        ];

        foreach ($flow as [$from, $to]) {
            $this->assertSame(
                ['kind' => OrderStatus::KIND_FORWARD, 'reason_required' => false],
                OrderStatus::classifyTransition($from, $to),
                "{$from} -> {$to}"
            );
        }
    }

    public function test_waiting_states_enter_and_leave_without_reason(): void
    {
        foreach ([OrderStatus::AWAITING_PART, OrderStatus::AWAITING_CUSTOMER] as $waiting) {
            $this->assertFalse(OrderStatus::classifyTransition(OrderStatus::REPAIR_IN_PROGRESS, $waiting)['reason_required']);
            $this->assertFalse(OrderStatus::classifyTransition($waiting, OrderStatus::REPAIR_IN_PROGRESS)['reason_required']);
            $this->assertFalse(OrderStatus::classifyTransition($waiting, OrderStatus::IN_DIAGNOSIS)['reason_required']);
        }
    }

    public function test_cancellation_and_not_executed_require_reason(): void
    {
        foreach ([OrderStatus::OPEN, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::AWAITING_PART] as $from) {
            $this->assertTrue(OrderStatus::classifyTransition($from, OrderStatus::CANCELLED)['reason_required']);
            $this->assertTrue(OrderStatus::classifyTransition($from, OrderStatus::SERVICE_NOT_EXECUTED)['reason_required']);
        }
    }

    public function test_backward_moves_are_regressions_with_reason(): void
    {
        $decision = OrderStatus::classifyTransition(OrderStatus::SERVICE_COMPLETED, OrderStatus::REPAIR_IN_PROGRESS);

        $this->assertSame(OrderStatus::KIND_REGRESSION, $decision['kind']);
        $this->assertTrue($decision['reason_required']);

        $this->assertSame(
            OrderStatus::KIND_CORRECTION,
            OrderStatus::classifyTransition(OrderStatus::SERVICE_COMPLETED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::KIND_CORRECTION)['kind']
        );
    }

    public function test_terminal_statuses_only_leave_by_reopen_or_correction(): void
    {
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::DELIVERED, OrderStatus::REPAIR_IN_PROGRESS));
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::CANCELLED, OrderStatus::OPEN, OrderStatus::KIND_REGRESSION));

        $this->assertSame(
            ['kind' => OrderStatus::KIND_REOPEN, 'reason_required' => true],
            OrderStatus::classifyTransition(OrderStatus::DELIVERED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::KIND_REOPEN)
        );
        $this->assertSame(
            ['kind' => OrderStatus::KIND_CORRECTION, 'reason_required' => true],
            OrderStatus::classifyTransition(OrderStatus::DELIVERED, OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::KIND_CORRECTION)
        );
    }

    public function test_terminal_to_terminal_is_always_invalid(): void
    {
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::CANCELLED, OrderStatus::DELIVERED, OrderStatus::KIND_REOPEN));
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::DELIVERED, OrderStatus::CANCELLED, OrderStatus::KIND_CORRECTION));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::DELIVERED, OrderStatus::CANCELLED));
    }

    public function test_unknown_and_retired_codes_are_invalid(): void
    {
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::OPEN, 11));
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::OPEN, 12));
        $this->assertNull(OrderStatus::classifyTransition(OrderStatus::OPEN, 99));
        $this->assertFalse(OrderStatus::canTransition(null, 99));
    }

    public function test_matrix_covers_every_pair(): void
    {
        $values = OrderStatus::values();
        $matrix = OrderStatus::matrix();

        $this->assertCount(13, $values);

        foreach ($values as $from) {
            $this->assertCount(count($values) - 1, $matrix[$from]);
        }
    }
}
