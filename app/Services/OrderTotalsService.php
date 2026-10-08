<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderItem;
use Illuminate\Validation\ValidationException;

/**
 * Fonte da verdade dos totais da OS. Nada vindo do navegador (parts_value, service_cost)
 * é persistido sem passar por aqui.
 *
 *   subtotal_servicos = orders.service_value
 *   subtotal_pecas    = Σ itens de peça de estoque (preço congelado) + orders.manual_parts_value
 *   total             = subtotal_servicos + subtotal_pecas + surcharge_amount − discount_amount
 *
 * Compatibilidade: orders.parts_value guarda subtotal_pecas e orders.service_cost guarda o
 * total (o nome é legado; todos os consumidores — pagamentos, recebíveis, relatórios, PDFs,
 * fiscal — já o leem como total). Use Order::total() / este serviço em código novo.
 */
class OrderTotalsService
{
    /**
     * @return array{services: float, stock_parts: float, manual_parts: float, parts: float, discount: float, surcharge: float, total: float}
     */
    public function breakdown(Order $order): array
    {
        $services = round((float) ($order->service_value ?? 0), 2);
        $stockParts = round((float) OrderItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('source_type', OrderItem::SOURCE_PART)
            ->sum('total_price'), 2);
        $manualParts = round((float) ($order->manual_parts_value ?? 0), 2);
        $discount = round((float) ($order->discount_amount ?? 0), 2);
        $surcharge = round((float) ($order->surcharge_amount ?? 0), 2);
        $parts = round($stockParts + $manualParts, 2);

        return [
            'services' => $services,
            'stock_parts' => $stockParts,
            'manual_parts' => $manualParts,
            'parts' => $parts,
            'discount' => $discount,
            'surcharge' => $surcharge,
            'total' => round($services + $parts + $surcharge - $discount, 2),
        ];
    }

    /**
     * Rateio do desconto e do acréscimo da OS entre serviços e peças, proporcional aos
     * subtotais. A parcela dos serviços é a diferença para a parcela das peças, de modo
     * que serviços + peças reproduzam exatamente os valores da OS (sem centavo perdido).
     *
     * @return array{services_gross: float, services_discount: float, services_surcharge: float, services_net: float}
     */
    public static function servicesShare(float $services, float $parts, float $discount, float $surcharge): array
    {
        $base = round($services + $parts, 2);
        $partsShare = fn (float $value): float => $base > 0 ? round($value * $parts / $base, 2) : 0.0;

        $servicesDiscount = round($discount - $partsShare($discount), 2);
        $servicesSurcharge = round($surcharge - $partsShare($surcharge), 2);

        return [
            'services_gross' => round($services, 2),
            'services_discount' => $servicesDiscount,
            'services_surcharge' => $servicesSurcharge,
            'services_net' => round($services + $servicesSurcharge - $servicesDiscount, 2),
        ];
    }

    /**
     * Recalcula e persiste parts_value e service_cost (total). Deve rodar depois da
     * reconciliação dos itens, na mesma transação.
     *
     * @return array{services: float, stock_parts: float, manual_parts: float, parts: float, discount: float, surcharge: float, total: float}
     */
    public function recalculate(Order $order): array
    {
        $totals = $this->breakdown($order);

        foreach (['manual_parts' => 'manual_parts_value', 'discount' => 'discount_amount', 'surcharge' => 'surcharge_amount'] as $key => $field) {
            if ($totals[$key] < 0) {
                throw ValidationException::withMessages([$field => 'O valor não pode ser negativo.']);
            }
        }

        if ($totals['total'] < 0) {
            throw ValidationException::withMessages([
                'discount_amount' => 'O desconto não pode ser maior que serviços + peças + acréscimo.',
            ]);
        }

        $order->forceFill([
            'parts_value' => $totals['parts'],
            'service_cost' => $totals['total'],
            'totals_calculated_at' => now(),
        ])->save();

        return $totals;
    }
}
