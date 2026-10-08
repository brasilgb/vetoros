<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Support\PartCostPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única camada que altera as peças de estoque vinculadas à OS (order_parts),
 * o saldo das peças e os movimentos uso_os/devolucao.
 *
 * Concorrência: a OS e as peças envolvidas são travadas (lockForUpdate) e o vínculo
 * atual é lido depois da trava, dentro da transação. Dois salvamentos simultâneos da
 * mesma OS são serializados e nenhum baixa estoque a partir de uma leitura antiga.
 */
class OrderPartsService
{
    /**
     * Define as quantidades finais de peças da OS.
     *
     * @param  array<int, int>  $nextQuantities  part_id => quantidade (0 remove)
     * @return array{added: list<int>, movements: list<array<string, mixed>>}
     */
    public function sync(Order $order, array $nextQuantities, ?int $userId): array
    {
        return DB::transaction(function () use ($order, $nextQuantities, $userId): array {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $current = DB::table('order_parts')
                ->where('order_id', $order->id)
                ->pluck('quantity', 'part_id')
                ->mapWithKeys(fn ($quantity, $partId) => [(int) $partId => (int) $quantity])
                ->all();

            $next = collect($nextQuantities)
                ->mapWithKeys(fn ($quantity, $partId) => [(int) $partId => max(0, (int) $quantity)])
                ->filter(fn (int $quantity): bool => $quantity > 0)
                ->all();

            $partIds = array_values(array_unique(array_merge(array_keys($current), array_keys($next))));
            $parts = Part::withoutGlobalScopes()
                ->whereIn('id', $partIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $added = [];
            $movements = [];

            foreach ($partIds as $partId) {
                $quantityDiff = ($next[$partId] ?? 0) - ($current[$partId] ?? 0);

                if ($quantityDiff === 0) {
                    continue;
                }

                $part = $parts->get($partId);

                if (! $part || (int) $part->tenant_id !== (int) $order->tenant_id) {
                    throw ValidationException::withMessages([
                        'allparts' => 'Uma das peças informadas não foi encontrada.',
                    ]);
                }

                if (! isset($current[$partId])) {
                    $added[] = $partId;
                }

                $movements[] = $this->move($order, $part, $quantityDiff, $userId, 'da OS');
            }

            $order->orderParts()->sync(
                collect($next)->mapWithKeys(fn (int $quantity, int $partId) => [$partId => ['quantity' => $quantity]])->all()
            );

            return ['added' => $added, 'movements' => $movements];
        });
    }

    /**
     * Devolve ao estoque todas as peças da OS (com movimento e custo) e desfaz o vínculo.
     * Usado na exclusão da OS; roda na transação do chamador.
     */
    public function returnAll(Order $order, ?int $userId): void
    {
        DB::transaction(function () use ($order, $userId): void {
            $partIds = DB::table('order_parts')->where('order_id', $order->id)->pluck('part_id');

            foreach ($partIds as $partId) {
                $this->remove($order, (int) $partId, $userId);
            }
        });
    }

    /**
     * Remove uma peça da OS, devolvendo-a ao estoque.
     */
    public function remove(Order $order, int $partId, ?int $userId): bool
    {
        return DB::transaction(function () use ($order, $partId, $userId): bool {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $quantity = (int) DB::table('order_parts')
                ->where('order_id', $order->id)
                ->where('part_id', $partId)
                ->value('quantity');

            if ($quantity <= 0) {
                return false;
            }

            $part = Part::withoutGlobalScopes()->whereKey($partId)->lockForUpdate()->first();

            if ($part && (int) $part->tenant_id === (int) $order->tenant_id) {
                $this->move($order, $part, -$quantity, $userId, 'removida da OS');
            }

            $order->orderParts()->detach($partId);

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function move(Order $order, Part $part, int $quantityDiff, ?int $userId, string $returnContext): array
    {
        if ($quantityDiff > 0) {
            if ((int) $part->quantity < $quantityDiff) {
                throw ValidationException::withMessages([
                    'allparts' => "Estoque insuficiente para {$part->name}.",
                ]);
            }

            $part->decrement('quantity', $quantityDiff);
            $type = PartMovement::TYPE_ORDER_USE;
            $quantity = $quantityDiff;
            $reason = 'Uso na OS '.$order->order_number;
        } else {
            $quantity = abs($quantityDiff);
            $part->increment('quantity', $quantity);
            $type = PartMovement::TYPE_RETURN;
            $reason = 'Devolução de peça '.$returnContext.' '.$order->order_number;
        }

        // Custo vigente (média) no momento do movimento; devolução não altera a média.
        PartMovement::create([
            'tenant_id' => $order->tenant_id,
            'part_id' => $part->id,
            'order_id' => $order->id,
            'user_id' => $userId,
            'movement_type' => $type,
            'quantity' => $quantity,
            ...PartCostPolicy::currentMovementCost($part, $quantity),
            'reason' => $reason,
        ]);

        return [
            'part_id' => (int) $part->id,
            'part_name' => $part->name,
            'movement_type' => $type,
            'quantity' => $quantity,
        ];
    }
}
