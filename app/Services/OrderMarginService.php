<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderCommission;
use App\Models\App\OrderItem;
use App\Models\App\OrderPayment;
use App\Support\OrderStatus;

/**
 * Componentes da margem de uma OS, cada um com o seu grau de conhecimento:
 * - "known"   : valor comprovado (pode ser zero);
 * - "unknown" : existe mas o valor não é conhecido (legado, não informado);
 * - "pending" : ainda não definido (ex.: comissão antes da entrega).
 *
 * margem = receita (total da OS) − taxas − custo das peças de estoque − custo avulso − comissão.
 * A margem só é calculada quando todos os componentes são "known"; caso contrário é null,
 * para nunca exibir uma margem enganosa.
 */
class OrderMarginService
{
    public const KNOWN = 'known';

    public const UNKNOWN = 'unknown';

    public const PENDING = 'pending';

    public function __construct(
        private readonly OrderTotalsService $totals,
        private readonly TechnicianCommissionService $commissions,
    ) {}

    /**
     * @return array{revenue: array<string, float>, costs: array<string, array{amount: float|null, status: string}>, margin: float|null, complete: bool}
     */
    public function breakdown(Order $order): array
    {
        $revenue = $this->totals->breakdown($order);

        $costs = [
            'stock_parts' => $this->stockPartsCost($order),
            'manual_parts' => $this->manualPartsCost($order, $revenue['manual_parts']),
            'commission' => $this->commission($order),
            'payment_fees' => $this->paymentFees($order, $revenue['total']),
        ];

        $complete = collect($costs)->every(fn (array $cost) => $cost['status'] === self::KNOWN);
        $margin = $complete
            ? round($revenue['total'] - collect($costs)->sum(fn (array $cost) => $cost['amount']), 2)
            : null;

        return ['revenue' => $revenue, 'costs' => $costs, 'margin' => $margin, 'complete' => $complete];
    }

    /**
     * @return array{amount: float|null, status: string}
     */
    private function stockPartsCost(Order $order): array
    {
        $items = OrderItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('source_type', OrderItem::SOURCE_PART)
            ->get(['total_cost']);

        if ($items->contains(fn (OrderItem $item) => $item->total_cost === null)) {
            return ['amount' => null, 'status' => self::UNKNOWN];
        }

        return ['amount' => round((float) $items->sum('total_cost'), 2), 'status' => self::KNOWN];
    }

    /**
     * @return array{amount: float|null, status: string}
     */
    private function manualPartsCost(Order $order, float $manualValue): array
    {
        if ($order->manual_parts_cost !== null) {
            return ['amount' => round((float) $order->manual_parts_cost, 2), 'status' => self::KNOWN];
        }

        // Sem material avulso vendido, não há custo avulso; vendido sem custo informado = desconhecido.
        return $manualValue > 0
            ? ['amount' => null, 'status' => self::UNKNOWN]
            : ['amount' => 0.0, 'status' => self::KNOWN];
    }

    /**
     * @return array{amount: float|null, status: string}
     */
    private function commission(Order $order): array
    {
        $commission = OrderCommission::withoutGlobalScopes()->where('order_id', $order->id)->first();

        if ($commission) {
            return ['amount' => round((float) $commission->commission_amount, 2), 'status' => self::KNOWN];
        }

        if ((int) $order->service_status !== OrderStatus::DELIVERED) {
            return ['amount' => null, 'status' => self::PENDING];
        }

        // Entregue sem comissão: zero só quando não há técnico/percentual elegível. Elegível e
        // ainda não consolidada (ex.: entrega confirmada pela área pública) = pendente.
        return $this->commissions->isEligible($order)
            ? ['amount' => null, 'status' => self::PENDING]
            : ['amount' => 0.0, 'status' => self::KNOWN];
    }

    /**
     * @return array{amount: float|null, status: string}
     */
    private function paymentFees(Order $order, float $revenueTotal): array
    {
        $payments = OrderPayment::query()->where('order_id', $order->id)->get(['amount', 'fee_amount']);

        if ($payments->contains(fn (OrderPayment $payment) => $payment->fee_amount === null)) {
            return ['amount' => null, 'status' => self::UNKNOWN];
        }

        // Saldo em aberto: as taxas dos pagamentos que faltam ainda não existem.
        if (round($revenueTotal - (float) $payments->sum('amount'), 2) > 0) {
            return ['amount' => null, 'status' => self::PENDING];
        }

        return ['amount' => round((float) $payments->sum('fee_amount'), 2), 'status' => self::KNOWN];
    }
}
