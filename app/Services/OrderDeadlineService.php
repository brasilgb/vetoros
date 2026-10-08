<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderEvent;
use App\Support\OrderActor;
use Illuminate\Support\Carbon;

/**
 * Prazo prometido (delivery_forecast) e data real de entrega (delivery_date).
 *
 * - original_delivery_forecast: primeiro prazo prometido, gravado só na criação e nunca alterado.
 * - delivery_forecast: prazo vigente; cada mudança gera delivery_forecast_changed.
 * - delivery_date: fato da entrega; nunca é apagada ao sair de "Entregue". Correções geram
 *   delivery_date_changed; cada nova entrega é um status_changed → 10 com delivered_at.
 */
class OrderDeadlineService
{
    public function __construct(private readonly OrderEventRecorder $recorder) {}

    /**
     * Na criação: o prazo informado é o prazo original.
     */
    public function recordInitialForecast(Order $order): void
    {
        $forecast = self::normalizeDate($order->delivery_forecast);

        if ($forecast && ! $order->original_delivery_forecast) {
            $order->forceFill(['original_delivery_forecast' => $forecast])->save();
        }
    }

    public function changeForecast(Order $order, ?string $newForecast, OrderActor $actor, ?string $reason = null): void
    {
        $previous = self::normalizeDate($order->delivery_forecast);
        $next = self::normalizeDate($newForecast);

        if ($previous === $next) {
            return;
        }

        $original = self::normalizeDate($order->original_delivery_forecast);

        $order->forceFill(['delivery_forecast' => $next])->save();

        $this->recorder->record($order, OrderEvent::TYPE_DELIVERY_FORECAST_CHANGED, $actor, [
            'reason' => $reason ?: null,
        ], [
            'previous' => $previous,
            'new' => $next,
            // OS legada não tem o prazo original registrado: não é inferido.
            'original' => $original,
            'original_known' => $original !== null,
        ]);
    }

    /**
     * Correção da data de uma entrega já registrada (OS continua em "Entregue").
     */
    public function correctDeliveryDate(Order $order, Carbon $deliveredAt, OrderActor $actor): void
    {
        $previous = $order->delivery_date ? Carbon::parse($order->delivery_date) : null;

        if ($previous && $previous->equalTo($deliveredAt)) {
            return;
        }

        $order->forceFill(['delivery_date' => $deliveredAt])->save();

        $this->recorder->record($order, OrderEvent::TYPE_DELIVERY_DATE_CHANGED, $actor, [], [
            'previous' => $previous?->toDateTimeString(),
            'new' => $deliveredAt->toDateTimeString(),
        ]);
    }

    public static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
