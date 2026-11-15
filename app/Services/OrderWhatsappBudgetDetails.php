<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderItem;
use App\Models\App\OrderBudgetItem;
use Illuminate\Support\Collection;

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

        return $this->details($budget, $budget->items()
            ->where('item_type', OrderItem::TYPE_PRODUCT)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());
    }

    /**
     * Versão em lote de forOrder() para a listagem: consultas fixas (aprovados, versões
     * vigentes e itens), independentes do número de OS na página. Mesmo resultado por OS.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array{description: string|null, parts: list<array{quantity: float, description: string, total: float}>, parts_value: float|null}|null>
     */
    public function forOrders(Collection $orders): array
    {
        $orders = $orders->values();

        if ($orders->isEmpty()) {
            return [];
        }

        $approvedIds = $orders->pluck('approved_budget_id')->filter()->unique()->values()->all();
        $approved = $approvedIds === []
            ? collect()
            : OrderBudget::query()->whereIn('id', $approvedIds)->get()->keyBy('id');

        $budgetFor = fn (Order $order) => $order->approved_budget_id !== null && $approved->has($order->approved_budget_id)
            ? $approved->get($order->approved_budget_id)
            : null;

        $fallbackOrderIds = $orders
            ->filter(fn (Order $order) => $budgetFor($order) === null)
            ->pluck('id')
            ->all();

        $latest = $fallbackOrderIds === []
            ? collect()
            : OrderBudget::withoutGlobalScopes()
                ->whereIn('order_id', $fallbackOrderIds)
                ->orderByDesc('version')
                ->get()
                ->groupBy('order_id')
                ->map(fn (Collection $versions) => $versions->first());

        $resolved = $orders->mapWithKeys(fn (Order $order) => [
            $order->id => $budgetFor($order) ?? $latest->get($order->id),
        ]);

        $budgetIds = $resolved->filter()->map(fn (OrderBudget $budget) => $budget->id)->values()->all();

        $items = $budgetIds === []
            ? collect()
            : OrderBudgetItem::query()
                ->whereIn('order_budget_id', $budgetIds)
                ->where('item_type', OrderItem::TYPE_PRODUCT)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('order_budget_id');

        return $resolved->map(fn ($budget) => $budget === null
            ? null
            : $this->details($budget, $items->get($budget->id, collect())))->all();
    }

    /**
     * @param  iterable<int, OrderBudgetItem|OrderItem>  $productItems
     * @return array{description: string|null, parts: list<array{quantity: float, description: string, total: float}>, parts_value: float|null}
     */
    private function details(OrderBudget $budget, iterable $productItems): array
    {
        $parts = collect($productItems)
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
