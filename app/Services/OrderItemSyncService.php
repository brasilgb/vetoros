<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderItem;
use Illuminate\Support\Collection;

/**
 * Reconcilia os itens financeiros da OS (order_items) sem apagar e recriar.
 *
 * Identidade de cada item:
 * - serviço: source_type = order_service (um por OS);
 * - peças avulsas: source_type = manual_parts (um por OS);
 * - peça de estoque: source_type = part + source_id = part_id (um por peça, igual a order_parts).
 *
 * Preço e custo de peça são congelados na inclusão (pricing_snapshot_at). Item existente
 * mantém unit_price/unit_cost em qualquer salvamento ou mudança de quantidade; trocar a
 * peça por outra é remover um item e incluir outro, com novo snapshot.
 */
class OrderItemSyncService
{
    /**
     * @param  list<int>  $addedPartIds  peças incluídas na operação atual (snapshot no momento da inclusão)
     */
    public function sync(Order $order, array $addedPartIds = []): void
    {
        $existing = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->get();

        $this->syncServiceItem($order, $existing);
        $this->syncManualPartsItem($order, $existing);
        $this->syncStockPartItems($order, $existing, $addedPartIds);
    }

    private function syncServiceItem(Order $order, Collection $existing): void
    {
        $value = round((float) ($order->service_value ?? 0), 2);
        $item = $existing->firstWhere('source_type', OrderItem::SOURCE_ORDER_SERVICE);

        if ($value <= 0) {
            $item?->delete();

            return;
        }

        // O preço do serviço é o valor informado na OS: é o preço praticado, sem cadastro externo.
        $this->upsert($order, $item, [
            'item_type' => OrderItem::TYPE_SERVICE,
            'source_type' => OrderItem::SOURCE_ORDER_SERVICE,
            'source_id' => null,
            'description' => $order->services_performed
                ?: ($order->budget_description ?: 'Serviço da OS '.$order->order_number),
            'quantity' => 1,
            'unit_price' => $value,
            'total_price' => $value,
            'unit_cost' => null,
            'total_cost' => null,
            'sort_order' => 10,
        ]);
    }

    private function syncManualPartsItem(Order $order, Collection $existing): void
    {
        $value = round((float) ($order->manual_parts_value ?? 0), 2);
        $item = $existing->firstWhere('source_type', OrderItem::SOURCE_MANUAL_PARTS);
        $cost = $order->manual_parts_cost === null ? null : round((float) $order->manual_parts_cost, 2);

        if ($value <= 0) {
            $item?->delete();

            return;
        }

        $this->upsert($order, $item, [
            'item_type' => OrderItem::TYPE_PRODUCT,
            'source_type' => OrderItem::SOURCE_MANUAL_PARTS,
            'source_id' => null,
            'description' => 'Peças e materiais avulsos',
            'quantity' => 1,
            'unit_price' => $value,
            'total_price' => $value,
            // Custo informado na OS (null = desconhecido; nunca inferido do preço de venda).
            'unit_cost' => $cost,
            'total_cost' => $cost,
            'sort_order' => 90,
        ]);
    }

    /**
     * @param  list<int>  $addedPartIds
     */
    private function syncStockPartItems(Order $order, Collection $existing, array $addedPartIds): void
    {
        $parts = $order->orderParts()->orderBy('parts.name')->get();
        $items = $existing->where('source_type', OrderItem::SOURCE_PART)->keyBy(fn (OrderItem $item) => (int) $item->source_id);
        $keptPartIds = [];

        foreach ($parts->values() as $index => $part) {
            $quantity = (float) ($part->pivot->quantity ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $keptPartIds[] = (int) $part->id;
            $item = $items->get((int) $part->id);

            if ($item) {
                // Preço e custo já congelados: só a quantidade (e os totais derivados) mudam.
                $unitPrice = (float) $item->unit_price;
                $unitCost = $item->unit_cost === null ? null : (float) $item->unit_cost;

                $item->forceFill([
                    'quantity' => $quantity,
                    'total_price' => round($quantity * $unitPrice, 2),
                    'total_cost' => $unitCost === null ? null : round($quantity * $unitCost, 2),
                    'sort_order' => 20 + $index,
                ])->save();

                continue;
            }

            $isNew = in_array((int) $part->id, $addedPartIds, true);
            $unitPrice = round((float) ($part->sale_price ?? 0), 2);
            // Peça incluída agora: custo vigente congelado. Peça antiga sem item (legado):
            // o custo do momento da inclusão é desconhecido e não é inventado.
            $unitCost = $isNew && $part->cost_price !== null ? round((float) $part->cost_price, 2) : null;

            OrderItem::create([
                'tenant_id' => OrderEventRecorder::tenantOf($order),
                'order_id' => $order->id,
                'item_type' => OrderItem::TYPE_PRODUCT,
                'source_type' => OrderItem::SOURCE_PART,
                'source_id' => $part->id,
                'description' => $part->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($quantity * $unitPrice, 2),
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost === null ? null : round($quantity * $unitCost, 2),
                'pricing_snapshot_at' => $isNew ? now() : null,
                'sort_order' => 20 + $index,
            ]);
        }

        // Remove somente os itens de peças que saíram da OS; os demais ficam intactos.
        $items->reject(fn (OrderItem $item, int $partId) => in_array($partId, $keptPartIds, true))
            ->each(fn (OrderItem $item) => $item->delete());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(Order $order, ?OrderItem $item, array $attributes): void
    {
        if ($item) {
            $item->forceFill($attributes)->save();

            return;
        }

        OrderItem::create([
            ...$attributes,
            'tenant_id' => OrderEventRecorder::tenantOf($order),
            'order_id' => $order->id,
            'pricing_snapshot_at' => now(),
        ]);
    }
}
