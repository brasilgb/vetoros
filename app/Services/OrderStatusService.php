<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Models\App\OrderStatusHistory;
use App\Support\OrderActor;
use App\Support\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Camada única que altera orders.service_status. Valida a matriz de transições,
 * grava o status atual, mantém order_status_history (legado) e registra o evento
 * imutável em order_events. Notificações continuam sendo disparadas pelos chamadores,
 * preservando o comportamento de cada fluxo.
 */
class OrderStatusService
{
    public function __construct(
        private readonly OrderEventRecorder $recorder,
        private readonly OrderTechnicianAssignmentService $assignments,
        private readonly OrderDeadlineService $deadlines,
    ) {}

    /**
     * Registra a criação da OS: evento order_created com o status inicial, histórico
     * legado e atribuição inicial do técnico, quando houver.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordCreation(Order $order, OrderActor $actor, array $metadata = []): void
    {
        $status = (int) $order->service_status;

        if (! OrderStatus::isValid($status)) {
            throw ValidationException::withMessages(['service_status' => 'Status inicial inválido.']);
        }

        DB::transaction(function () use ($order, $actor, $metadata, $status): void {
            $this->recordLegacyHistory($order, $status, $actor, null);

            $this->deadlines->recordInitialForecast($order);

            $this->recorder->record($order, OrderEvent::TYPE_ORDER_CREATED, $actor, [
                'to_status' => $status,
                'transition_kind' => OrderStatus::KIND_INITIAL,
            ], array_filter([
                ...$metadata,
                'delivery_forecast' => OrderDeadlineService::normalizeDate($order->delivery_forecast),
            ], fn ($value) => $value !== null));

            $this->assignments->recordInitial($order, $actor);
        });
    }

    /**
     * Valida a transição sem gravar nada. Lança ValidationException com mensagem para o usuário.
     *
     * @return array{kind: string, reason_required: bool}|null null quando não há mudança
     */
    public function assertTransition(int $from, int $to, ?string $reason = null, ?string $requestedKind = null): ?array
    {
        if ($from === $to) {
            return null;
        }

        $decision = OrderStatus::classifyTransition($from, $to, $requestedKind);

        if ($decision === null) {
            $message = OrderStatus::isTerminal($from) && ! OrderStatus::isTerminal($to)
                ? sprintf('A ordem está "%s". Para alterar, informe se é reabertura ou correção de lançamento e o motivo.', OrderStatus::label($from))
                : sprintf('Transição inválida de status: %s para %s.', OrderStatus::label($from), OrderStatus::label($to));

            throw ValidationException::withMessages(['service_status' => $message]);
        }

        if ($decision['reason_required'] && trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'status_reason' => sprintf(
                    'Informe o motivo para alterar de "%s" para "%s".',
                    OrderStatus::label($from),
                    OrderStatus::label($to)
                ),
            ]);
        }

        return $decision;
    }

    /**
     * Ao entrar em "Entregue", grava delivery_date ($deliveredAt ou agora). Ao sair de
     * "Entregue", a data da entrega anterior é preservada (fato histórico).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Order $order,
        int $toStatus,
        OrderActor $actor,
        ?string $reason = null,
        ?string $requestedKind = null,
        array $metadata = [],
        ?Carbon $deliveredAt = null,
    ): Order {
        return DB::transaction(function () use ($order, $toStatus, $actor, $reason, $requestedKind, $metadata, $deliveredAt): Order {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fromStatus = (int) $locked->service_status;
            $reason = trim((string) $reason) !== '' ? trim((string) $reason) : null;

            $decision = $this->assertTransition($fromStatus, $toStatus, $reason, $requestedKind);

            if ($decision === null) {
                return $order;
            }

            $changes = ['service_status' => $toStatus];

            if ($toStatus === OrderStatus::DELIVERED) {
                $changes['delivery_date'] = $deliveredAt ?? now();
                $metadata['delivered_at'] = Carbon::parse($changes['delivery_date'])->toDateTimeString();
            }

            if ($fromStatus === OrderStatus::DELIVERED && $locked->delivery_date) {
                $metadata['previous_delivery_date'] = Carbon::parse($locked->delivery_date)->toDateTimeString();
            }

            $order->forceFill($changes)->save();

            $this->recordLegacyHistory($order, $toStatus, $actor, $reason);

            $this->recorder->record(
                $order,
                $decision['kind'] === OrderStatus::KIND_REOPEN ? OrderEvent::TYPE_ORDER_REOPENED : OrderEvent::TYPE_STATUS_CHANGED,
                $actor,
                [
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'transition_kind' => $decision['kind'],
                    'reason' => $reason,
                ],
                $metadata,
            );

            return $order->fresh();
        });
    }

    /**
     * Ação do cliente na área pública que não é, por si só, uma transição de status
     * (aviso recebido, retirada confirmada). Se a ação provocou mudança de status,
     * informe o status posterior; a transição em si já gerou seu status_changed.
     */
    public function recordCustomerAction(Order $order, string $eventType, int $fromStatus, ?int $toStatus): OrderEvent
    {
        return $this->recorder->record($order, $eventType, OrderActor::customer(), [
            'from_status' => $fromStatus,
            'to_status' => $toStatus !== $fromStatus ? $toStatus : null,
        ], ['channel' => 'public_tracking']);
    }

    private function recordLegacyHistory(Order $order, int $status, OrderActor $actor, ?string $reason): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => $status,
            'changed_by' => $actor->userId,
            'note' => mb_substr($reason ?? OrderStatus::label($status), 0, 255),
        ]);
    }
}
