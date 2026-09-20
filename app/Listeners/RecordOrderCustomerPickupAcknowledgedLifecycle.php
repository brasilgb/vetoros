<?php

namespace App\Listeners;

use App\Events\OrderCustomerPickupAcknowledged;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderCustomerPickupAcknowledgedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderCustomerPickupAcknowledged $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_customer_pickup_acknowledged',
            'order',
            $order,
            null,
            $event->data,
        );
    }
}
