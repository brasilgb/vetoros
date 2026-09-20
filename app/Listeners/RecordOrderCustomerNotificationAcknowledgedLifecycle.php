<?php

namespace App\Listeners;

use App\Events\OrderCustomerNotificationAcknowledged;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderCustomerNotificationAcknowledgedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderCustomerNotificationAcknowledged $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_customer_notification_acknowledged',
            'order',
            $order,
            null,
            $event->data,
        );
    }
}
