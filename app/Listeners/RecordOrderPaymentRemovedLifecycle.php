<?php

namespace App\Listeners;

use App\Events\OrderPaymentRemoved;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderPaymentRemovedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderPaymentRemoved $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_payment_removed',
            'order',
            $order,
            $event->actorId,
            $event->data,
        );
    }
}
