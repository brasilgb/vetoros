<?php

namespace App\Services;

use App\Models\App\AccountPayable;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\PurchaseOrder;
use App\Models\App\PurchaseOrderItem;
use App\Models\App\PurchaseOrderLog;
use App\Support\PartCostPolicy;
use App\Support\TenantSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly AccountPayableService $accountPayableService) {}

    public function create(array $data, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $total = $this->itemsTotal($data['items']);

            $purchaseOrder = PurchaseOrder::create([
                'purchase_order_number' => TenantSequence::next(PurchaseOrder::class, 'purchase_order_number'),
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrder::STATUS_DRAFT,
                'total_amount' => $total,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncItems($purchaseOrder, $data['items']);

            $this->log($purchaseOrder, $userId, 'created', ['total_amount' => $total]);

            return $purchaseOrder->fresh('items');
        });
    }

    public function update(PurchaseOrder $purchaseOrder, array $data, ?int $userId = null): PurchaseOrder
    {
        $this->ensureStatus($purchaseOrder, PurchaseOrder::STATUS_DRAFT, 'Apenas ordens de compra em rascunho podem ser editadas.');

        return DB::transaction(function () use ($purchaseOrder, $data, $userId) {
            $total = $this->itemsTotal($data['items']);

            $purchaseOrder->update([
                'supplier_id' => $data['supplier_id'],
                'total_amount' => $total,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $purchaseOrder->items()->delete();
            $this->syncItems($purchaseOrder, $data['items']);

            $this->log($purchaseOrder, $userId, 'updated', ['total_amount' => $total]);

            return $purchaseOrder->fresh('items');
        });
    }

    public function send(PurchaseOrder $purchaseOrder, ?int $userId = null): PurchaseOrder
    {
        $this->ensureStatus($purchaseOrder, PurchaseOrder::STATUS_DRAFT, 'Apenas ordens de compra em rascunho podem ser enviadas.');

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_SENT]);
        $this->log($purchaseOrder, $userId, 'sent');

        return $purchaseOrder->fresh();
    }

    public function receive(PurchaseOrder $purchaseOrder, ?int $userId = null): PurchaseOrder
    {
        $this->ensureStatus($purchaseOrder, PurchaseOrder::STATUS_SENT, 'Apenas ordens de compra enviadas podem ser recebidas.');

        return DB::transaction(function () use ($purchaseOrder, $userId) {
            $items = $purchaseOrder->items()->with('part')->get();
            $parts = Part::query()->whereIn('id', $items->pluck('part_id'))->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                $part = $parts->get($item->part_id);

                if (! $part) {
                    continue;
                }

                // Custo médio ponderado móvel (PartCostPolicy), não mais o último custo de compra.
                $averageCost = PartCostPolicy::weightedAverage(
                    (int) $part->quantity,
                    $part->cost_price === null ? null : (float) $part->cost_price,
                    (int) $item->quantity,
                    (float) $item->unit_cost,
                );
                $part->forceFill([
                    'quantity' => (int) $part->quantity + (int) $item->quantity,
                    'cost_price' => $averageCost,
                ])->save();

                PartMovement::create([
                    'part_id' => $part->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'user_id' => $userId,
                    'movement_type' => PartMovement::TYPE_PURCHASE,
                    'quantity' => $item->quantity,
                    ...PartCostPolicy::movementCost((float) $item->unit_cost, (int) $item->quantity),
                    'reason' => 'Recebimento da compra '.$purchaseOrder->purchase_order_number,
                ]);
            }

            $purchaseOrder->update([
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $purchaseOrder->loadMissing('supplier');

            $this->accountPayableService->create([
                'supplier_name' => $purchaseOrder->supplier?->name,
                'source_type' => AccountPayable::SOURCE_PURCHASE_ORDER,
                'source_id' => $purchaseOrder->id,
                'description' => 'Compra '.$purchaseOrder->purchase_order_number.' - '.($purchaseOrder->supplier?->name ?? 'Fornecedor não informado'),
                'category' => 'Compras',
                'total_amount' => $purchaseOrder->total_amount,
                'due_date' => null,
            ], $userId);

            $this->log($purchaseOrder, $userId, 'received', ['total_amount' => $purchaseOrder->total_amount]);

            return $purchaseOrder->fresh(['items', 'supplier']);
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder, ?int $userId = null): PurchaseOrder
    {
        if (! in_array($purchaseOrder->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT], true)) {
            throw ValidationException::withMessages([
                'status' => 'Apenas ordens de compra em rascunho ou enviadas podem ser canceladas.',
            ]);
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        $this->log($purchaseOrder, $userId, 'cancelled');

        return $purchaseOrder->fresh();
    }

    public function delete(PurchaseOrder $purchaseOrder): void
    {
        $this->ensureStatus($purchaseOrder, PurchaseOrder::STATUS_DRAFT, 'Apenas ordens de compra em rascunho podem ser excluídas.');

        $purchaseOrder->delete();
    }

    private function syncItems(PurchaseOrder $purchaseOrder, array $items): void
    {
        foreach ($items as $item) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $purchaseOrder->id,
                'part_id' => $item['part_id'],
                'quantity' => $item['quantity'],
                'unit_cost' => round((float) $item['unit_cost'], 2),
            ]);
        }
    }

    private function itemsTotal(array $items): float
    {
        return round(array_sum(array_map(
            fn (array $item): float => (float) $item['quantity'] * (float) $item['unit_cost'],
            $items
        )), 2);
    }

    private function ensureStatus(PurchaseOrder $purchaseOrder, string $expected, string $message): void
    {
        if ($purchaseOrder->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function log(PurchaseOrder $purchaseOrder, ?int $userId, string $action, array $data = []): void
    {
        PurchaseOrderLog::create([
            'tenant_id' => $purchaseOrder->tenant_id,
            'purchase_order_id' => $purchaseOrder->id,
            'user_id' => $userId,
            'action' => $action,
            'data' => $data,
        ]);
    }
}
