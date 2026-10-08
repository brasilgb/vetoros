<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Support\OrderActor;
use App\Support\SensitiveData;
use LogicException;

class OrderEventRecorder
{
    /**
     * O tenant vem sempre da própria OS, nunca da sessão.
     */
    public static function tenantOf(Order $order): int
    {
        $tenantId = (int) ($order->getAttribute('tenant_id') ?? 0);

        if ($tenantId <= 0) {
            throw new LogicException('A OS não possui tenant; a trilha operacional exige tenant_id.');
        }

        return $tenantId;
    }

    /**
     * @param  array<string, mixed>  $attributes  colunas estruturadas (from_status, to_status, transition_kind, technician_id, previous_technician_id, reason)
     * @param  array<string, mixed>  $metadata  contexto mínimo do evento
     */
    public function record(Order $order, string $eventType, OrderActor $actor, array $attributes = [], array $metadata = []): OrderEvent
    {
        // Chaves sensíveis nunca entram em metadata, mesmo que um chamador as envie por engano.
        $metadata = SensitiveData::strip($metadata);

        return OrderEvent::create([
            ...$attributes,
            'tenant_id' => self::tenantOf($order),
            'order_id' => $order->id,
            'event_type' => $eventType,
            'actor_type' => $actor->type,
            'actor_id' => $actor->userId,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
