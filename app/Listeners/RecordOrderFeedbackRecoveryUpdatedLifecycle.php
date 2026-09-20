<?php

namespace App\Listeners;

use App\Events\OrderFeedbackRecoveryUpdated;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderFeedbackRecoveryUpdatedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderFeedbackRecoveryUpdated $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_feedback_recovery_updated',
            'order',
            $order,
            $event->actorId,
            $event->data,
        );
    }
}
