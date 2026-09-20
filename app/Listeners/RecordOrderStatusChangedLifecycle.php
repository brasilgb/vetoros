<?php

namespace App\Listeners;

use App\Events\OrderLifecycleStatusChanged;
use App\Models\App\Order;
use App\Services\OperationalAuditService;

class RecordOrderStatusChangedLifecycle
{
    public function __construct(private readonly OperationalAuditService $operationalAuditService) {}

    public function handle(OrderLifecycleStatusChanged $event): void
    {
        $order = Order::query()->find($event->orderId);

        if (! $order) {
            return;
        }

        $this->operationalAuditService->record(
            'order_status_changed',
            'order',
            $order,
            $event->actorId,
            $event->auditData,
        );
    }
}
