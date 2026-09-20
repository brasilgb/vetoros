<?php

namespace App\Listeners;

use App\Events\OrderCustomerFeedbackSubmitted;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderCustomerFeedbackSubmittedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderCustomerFeedbackSubmitted $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_customer_feedback_submitted',
            'order',
            $order,
            null,
            $event->data,
        );
    }
}
