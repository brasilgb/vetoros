<?php

namespace App\Listeners;

use App\Events\OrderPaymentRegistered;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderPaymentRegisteredLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderPaymentRegistered $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_payment_registered',
            'order',
            $order,
            $event->actorId,
            $event->data,
        );
    }
}
