<?php

namespace App\Listeners;

use App\Events\OrderLifecycleCreated;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderCreatedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderLifecycleCreated $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_created',
            'order',
            $order,
            $event->actorId,
            $event->data,
        );
    }
}
