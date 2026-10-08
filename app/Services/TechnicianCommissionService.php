<?php

namespace App\Services;

use App\Models\App\AccountPayable;
use App\Models\App\Order;
use App\Models\App\OrderCommission;
use App\Models\App\OrderTechnicianAssignment;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TechnicianCommissionService
{
    public function __construct(private readonly AccountPayableService $accountPayableService) {}

    /**
     * A comissão é consolidada uma única vez, na entrega: técnico, percentual e base ficam
     * congelados em order_commissions e nunca são recalculados com valores atuais.
     * Se a OS sair de "Entregue" (reabertura/correção), a comissão ainda não paga deixa
     * de valer; a já paga (total ou parcialmente) é preservada.
     */
    public function syncOrder(Order $order): ?OrderCommission
    {
        $isDelivered = (int) $order->service_status === OrderStatus::DELIVERED;
        $existing = OrderCommission::where('order_id', $order->id)->first();

        if ($existing) {
            if ($isDelivered || $this->hasPayment($existing)) {
                return $existing;
            }

            $this->removeCommission($existing);

            return null;
        }

        if (! $isDelivered) {
            return null;
        }

        $technician = $this->technicianAtDelivery($order);
        $percentage = $technician ? (float) ($technician->commission_percentage ?? 0) : 0;
        $baseAmount = round((float) ($order->service_value ?? 0), 2);

        if (! $technician || $percentage <= 0 || $baseAmount <= 0) {
            return null;
        }

        $commissionAmount = round($baseAmount * $percentage / 100, 2);

        return DB::transaction(function () use ($order, $technician, $baseAmount, $percentage, $commissionAmount, $existing) {
            $billData = [
                'supplier_name' => $technician->name,
                'description' => 'Comissão OS '.$order->order_number,
                'category' => 'Comissão de técnico',
                'total_amount' => $commissionAmount,
                'due_date' => $order->delivery_date ?? now()->toDateString(),
            ];

            $bill = $existing?->accountPayable;

            if ($bill) {
                $bill = $this->accountPayableService->update($bill, $billData);
            } else {
                $bill = $this->accountPayableService->create([
                    ...$billData,
                    'source_type' => AccountPayable::SOURCE_TECHNICIAN_COMMISSION,
                ]);
            }

            $commission = OrderCommission::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'tenant_id' => $order->tenant_id,
                    'user_id' => $technician->id,
                    'account_payable_id' => $bill->id,
                    'base_amount' => $baseAmount,
                    'commission_percentage' => $percentage,
                    'commission_amount' => $commissionAmount,
                ]
            );

            if ((int) $bill->source_id !== (int) $commission->id) {
                $bill->forceFill(['source_id' => $commission->id])->saveQuietly();
            }

            return $commission;
        });
    }

    public function deleteForOrder(Order $order): void
    {
        $existing = OrderCommission::where('order_id', $order->id)->first();

        if ($existing) {
            $this->removeCommission($existing);
        }
    }

    /**
     * Técnico responsável no momento da entrega (histórico de atribuições); sem histórico, o atual.
     */
    /**
     * A OS entregue gera comissão (técnico responsável na entrega com percentual e serviço
     * com valor)? Usado para distinguir "sem comissão" de "comissão ainda não consolidada".
     */
    public function isEligible(Order $order): bool
    {
        $technician = $this->technicianAtDelivery($order);

        return $technician
            && (float) ($technician->commission_percentage ?? 0) > 0
            && round((float) ($order->service_value ?? 0), 2) > 0;
    }

    private function technicianAtDelivery(Order $order): ?User
    {
        $technicianId = $order->delivery_date
            ? OrderTechnicianAssignment::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->activeAt(Carbon::parse($order->delivery_date))
                ->value('technician_id')
            : null;

        $technicianId ??= $order->user_id;

        return $technicianId ? User::withoutGlobalScopes()->find($technicianId) : null;
    }

    private function hasPayment(OrderCommission $commission): bool
    {
        return in_array($commission->accountPayable?->status, [AccountPayable::STATUS_PAID, AccountPayable::STATUS_PARTIAL], true);
    }

    private function removeCommission(OrderCommission $commission): void
    {
        $bill = $commission->accountPayable;
        $commission->delete();

        if ($bill && $bill->status !== AccountPayable::STATUS_PAID) {
            $this->accountPayableService->delete($bill);
        }
    }
}
