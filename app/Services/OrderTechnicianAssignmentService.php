<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\User;
use App\Support\OrderActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única camada que altera orders.user_id (técnico responsável atual) e mantém o
 * histórico temporal em order_technician_assignments + order_events.
 */
class OrderTechnicianAssignmentService
{
    public function __construct(private readonly OrderEventRecorder $recorder) {}

    /**
     * Registra a atribuição inicial de uma OS recém-criada (orders.user_id já gravado na criação).
     */
    public function recordInitial(Order $order, OrderActor $actor): void
    {
        $technicianId = $order->user_id ? (int) $order->user_id : null;

        if (! $technicianId) {
            return;
        }

        $this->assertTechnicianBelongsToOrderTenant($order, $technicianId);
        $this->open($order, $technicianId, $actor, null);
        $this->recorder->record($order, OrderEvent::TYPE_TECHNICIAN_ASSIGNED, $actor, [
            'technician_id' => $technicianId,
        ]);
    }

    /**
     * Define o técnico responsável. Mesmo técnico: nada é registrado.
     */
    public function assign(Order $order, ?int $technicianId, OrderActor $actor, ?string $reason = null): Order
    {
        $technicianId = $technicianId ?: null;

        return DB::transaction(function () use ($order, $technicianId, $actor, $reason): Order {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $previousId = $locked->user_id ? (int) $locked->user_id : null;

            if ($previousId === $technicianId) {
                return $order;
            }

            if ($technicianId) {
                $this->assertTechnicianBelongsToOrderTenant($locked, $technicianId);
            }

            $order->forceFill(['user_id' => $technicianId])->save();

            OrderTechnicianAssignment::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->whereNull('unassigned_at')
                ->get()
                ->each(fn (OrderTechnicianAssignment $assignment) => $assignment->update([
                    'unassigned_at' => now(),
                    'unassigned_by_type' => $actor->type,
                    'unassigned_by' => $actor->userId,
                ]));

            if ($technicianId) {
                $this->open($order, $technicianId, $actor, $reason);
            }

            $eventType = match (true) {
                $previousId === null => OrderEvent::TYPE_TECHNICIAN_ASSIGNED,
                $technicianId === null => OrderEvent::TYPE_TECHNICIAN_UNASSIGNED,
                default => OrderEvent::TYPE_TECHNICIAN_REASSIGNED,
            };

            $this->recorder->record($order, $eventType, $actor, [
                'technician_id' => $technicianId,
                'previous_technician_id' => $previousId,
                'reason' => $reason,
            ]);

            return $order;
        });
    }

    private function open(Order $order, int $technicianId, OrderActor $actor, ?string $reason): OrderTechnicianAssignment
    {
        return OrderTechnicianAssignment::create([
            'tenant_id' => OrderEventRecorder::tenantOf($order),
            'order_id' => $order->id,
            'technician_id' => $technicianId,
            'assigned_by_type' => $actor->type,
            'assigned_by' => $actor->userId,
            'assigned_at' => now(),
            'reason' => $reason,
        ]);
    }

    private function assertTechnicianBelongsToOrderTenant(Order $order, int $technicianId): void
    {
        $technicianTenantId = User::withoutGlobalScopes()->whereKey($technicianId)->value('tenant_id');

        if ((int) $technicianTenantId !== OrderEventRecorder::tenantOf($order)) {
            throw ValidationException::withMessages([
                'user_id' => 'O técnico informado não pertence à empresa desta ordem.',
            ]);
        }
    }
}
