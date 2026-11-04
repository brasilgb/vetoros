<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderItem;

/**
 * Dados do orçamento usados pelas tags {{ descricao_orcamento }}, {{ peças }} e
 * {{ valor_pecas }} dos modelos de WhatsApp. Usa a versão aprovada da OS; enquanto não
 * houver aprovação, a versão vigente. Lê só os snapshots da versão (nunca o estoque).
 */
class OrderWhatsappBudgetDetails
{
    /**
     * Retorna null quando a OS ainda não tem nenhuma versão de orçamento.
     *
     * @return array{description: string|null, parts: list<array{quantity: float, description: string, total: float}>, parts_value: float|null}|null
     */
    public function forOrder(Order $order): ?array
    {
        $budget = $order->approvedBudget
            ?? OrderBudget::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->orderByDesc('version')
                ->first();

        if (! $budget) {
            return null;
        }

        $parts = $budget->items()
            ->where('item_type', OrderItem::TYPE_PRODUCT)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => [
                'quantity' => (float) $item->quantity,
                'description' => trim((string) $item->description),
                'total' => round((float) $item->total_price, 2),
            ])
            ->values()
            ->all();

        $description = trim((string) $budget->description);

        return [
            'description' => $description !== '' ? $description : null,
            'parts' => $parts,
            'parts_value' => $budget->subtotal_parts === null ? null : round((float) $budget->subtotal_parts, 2),
        ];
    }
}
