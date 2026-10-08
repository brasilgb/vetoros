<?php

namespace App\Support;

use App\Models\App\Part;

/**
 * Política de custo do VetorOS: custo médio ponderado móvel, recalculado somente
 * em entradas de aquisição (recebimento de compra). Uso em OS, venda, devolução,
 * ajuste e cadastro não alteram a média; apenas registram o custo vigente.
 */
final class PartCostPolicy
{
    /**
     * novo custo = ((estoque anterior × custo anterior) + (qtd entrada × custo compra)) / nova quantidade.
     * Estoque anterior zero (ou custo anterior desconhecido) adota o custo da compra.
     */
    public static function weightedAverage(int $stockBefore, ?float $costBefore, int $quantityIn, float $unitCostIn): float
    {
        if ($quantityIn <= 0) {
            return round((float) $costBefore, 2);
        }

        if ($stockBefore <= 0 || $costBefore === null) {
            return round($unitCostIn, 2);
        }

        $value = ($stockBefore * $costBefore) + ($quantityIn * $unitCostIn);

        return round($value / ($stockBefore + $quantityIn), 2);
    }

    /**
     * Colunas de custo de um movimento de estoque.
     *
     * @return array{unit_cost: float|null, total_cost: float|null}
     */
    public static function movementCost(?float $unitCost, int|float $quantity): array
    {
        if ($unitCost === null) {
            return ['unit_cost' => null, 'total_cost' => null];
        }

        return [
            'unit_cost' => round($unitCost, 2),
            'total_cost' => round($unitCost * (float) $quantity, 2),
        ];
    }

    /**
     * Custo vigente da peça (média atual), para movimentos que não são aquisição.
     *
     * @return array{unit_cost: float|null, total_cost: float|null}
     */
    public static function currentMovementCost(Part $part, int|float $quantity): array
    {
        $cost = $part->getAttribute('cost_price');

        return self::movementCost($cost === null ? null : (float) $cost, $quantity);
    }
}
