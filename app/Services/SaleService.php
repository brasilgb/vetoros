<?php

namespace App\Services;

use App\Models\App\CashSession;
use App\Models\App\FiscalDocument;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\Sale;
use App\Models\App\SaleItem;
use App\Models\User;
use App\Support\PartCostPolicy;
use App\Support\TenantSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleService
{
    public function __construct(private readonly FinancialReceivableService $financialReceivableService) {}

    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $openCashSession = CashSession::query()
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();

            if (! $openCashSession) {
                throw new RuntimeException('Abra o caixa diário antes de concluir uma venda.');
            }

            $totalAmount = round((float) $data['total_amount'], 2);
            $paidAmount = round((float) ($data['paid_amount'] ?? $data['total_amount']), 2);

            if ($paidAmount > $totalAmount) {
                throw new RuntimeException('O valor pago não pode ser maior que o total da venda.');
            }

            $sale = Sale::create([
                'sales_number' => TenantSequence::next(Sale::class, 'sales_number'),
                'customer_id' => $data['customer_id'] ?? null,
                'cash_session_id' => $openCashSession->id,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'financial_status' => $this->resolveFinancialStatus($totalAmount, $paidAmount, 'completed'),
                'payment_method' => $data['payment_method'],
                'status' => 'completed',
            ]);

            foreach ($data['parts'] as $item) {
                $part = Part::lockForUpdate()->findOrFail($item['part_id']);

                if ($part->quantity < $item['quantity']) {
                    throw new RuntimeException("Estoque insuficiente para {$part->name}");
                }

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'part_id' => $part->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $part->sale_price,
                    'unit_cost' => $part->cost_price,
                ]);

                $part->decrement('quantity', $item['quantity']);
                PartMovement::create([
                    'part_id' => $part->id,
                    'user_id' => auth()->id(),
                    'movement_type' => PartMovement::TYPE_SALE,
                    'quantity' => $item['quantity'],
                    ...PartCostPolicy::currentMovementCost($part, $item['quantity']),
                    'reason' => 'Venda '.$sale->sales_number,
                ]);
            }

            $this->financialReceivableService->syncSale($sale->fresh());

            return $sale;
        });
    }

    public function cancel(Sale $sale, string $reason, ?User $user): Sale
    {
        if ($sale->status === 'cancelled') {
            throw new RuntimeException('Venda já está cancelada.');
        }

        if (FiscalDocument::hasActiveNativeFor($sale)) {
            throw new RuntimeException('Esta venda tem nota fiscal emitida ou em processamento. Cancele a nota em Notas fiscais antes de cancelar a venda.');
        }

        if ($sale->cashSession && $sale->cashSession->status === 'closed') {
            throw new RuntimeException('Não é possível cancelar venda vinculada a caixa já fechado.');
        }

        if ($user?->roles === User::ROLE_OPERATOR && $sale->created_at->diffInMinutes(now()) > 60) {
            throw new RuntimeException('Operador só pode cancelar vendas com até 60 minutos. Solicite um administrador.');
        }

        return DB::transaction(function () use ($sale, $reason, $user) {
            foreach ($sale->items as $item) {
                $part = Part::query()->whereKey($item->part_id)->lockForUpdate()->first();

                if ($part) {
                    $part->increment('quantity', $item->quantity);
                    PartMovement::create([
                        'part_id' => $part->id,
                        'user_id' => $user?->id,
                        'movement_type' => PartMovement::TYPE_RETURN,
                        'quantity' => $item->quantity,
                        // Volta ao estoque pelo custo com que saiu na venda, quando conhecido.
                        ...($item->unit_cost !== null
                            ? PartCostPolicy::movementCost((float) $item->unit_cost, $item->quantity)
                            : PartCostPolicy::currentMovementCost($part, $item->quantity)),
                        'reason' => 'Cancelamento da venda '.$sale->sales_number,
                    ]);
                }
            }

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user?->id,
                'cancel_reason' => $reason,
                'financial_status' => 'cancelled',
            ]);

            $this->financialReceivableService->syncSale($sale->fresh());

            return $sale->fresh();
        });
    }

    public function delete(Sale $sale, ?User $user): void
    {
        if ($sale->status !== 'cancelled') {
            throw new RuntimeException('Somente vendas canceladas podem ser excluídas.');
        }

        if (FiscalDocument::hasNativeHistoryFor($sale)) {
            throw new RuntimeException('Vendas com nota fiscal emitida pelo sistema não podem ser excluídas, para preservar o histórico fiscal.');
        }

        if (! $user?->isRoot() && ! $user?->isAdministrator()) {
            throw new RuntimeException('Apenas administradores podem excluir vendas.');
        }

        if ($sale->cashSession && $sale->cashSession->status === 'closed') {
            throw new RuntimeException('Não é possível excluir venda de caixa já fechado.');
        }

        DB::transaction(function () use ($sale) {
            $this->financialReceivableService->deleteSource('sale', (int) $sale->id, (int) $sale->tenant_id);
            $sale->delete();
        });
    }

    private function resolveFinancialStatus(float $totalAmount, float $paidAmount, string $saleStatus): string
    {
        if ($saleStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($paidAmount <= 0) {
            return 'pending';
        }

        if ($paidAmount < $totalAmount) {
            return 'partial';
        }

        return 'paid';
    }
}
