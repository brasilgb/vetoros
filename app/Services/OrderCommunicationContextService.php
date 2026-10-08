<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\Other;
use App\Support\OrderStatus;
use Illuminate\Support\Carbon;

/**
 * Centraliza as regras de "quando faz sentido contatar o cliente sobre uma ordem"
 * (orçamento parado, cobrança pendente, janela de feedback), para que a listagem
 * de Ordens e a de Clientes usem exatamente os mesmos critérios em vez de duas
 * implementações que podem divergir com o tempo.
 */
class OrderCommunicationContextService
{
    public function communicationThresholdDays(?int $tenantId): int
    {
        return Other::communicationFollowUpCooldownDays($tenantId);
    }

    public function isBudgetFollowUp(Order $order, ?int $tenantId): bool
    {
        if ((int) $order->service_status !== OrderStatus::BUDGET_GENERATED) {
            return false;
        }

        // Referência do orçamento (envio da versão / entrada no status), não a última edição da OS.
        return $order->budgetPendingSince()?->lte(now()->subDays($this->communicationThresholdDays($tenantId))) ?? false;
    }

    public function isPendingPayment(Order $order, ?int $tenantId, float $remaining): bool
    {
        if ($remaining <= 0.009) {
            return false;
        }

        if (! Other::financeEnabled($tenantId)) {
            return false;
        }

        if ((int) $order->service_status !== OrderStatus::DELIVERED) {
            return false;
        }

        return $order->delivery_date?->lte(now()->subDays($this->communicationThresholdDays($tenantId))) ?? false;
    }

    public function communicationDaysPending(Order $order): int
    {
        $referenceDate = (int) $order->service_status === OrderStatus::BUDGET_GENERATED
            ? $order->budgetPendingSince()
            : ($order->delivery_date ?? $order->updated_at ?? $order->created_at);

        return $referenceDate ? max(0, (int) $referenceDate->diffInDays(now())) : 0;
    }

    public function customerFeedbackRequestThreshold(?int $tenantId): Carbon
    {
        return Carbon::now()->subDays(Other::customerFeedbackRequestDelayDays($tenantId))->endOfDay();
    }

    public function customerFeedbackExpirationThreshold(?int $tenantId): Carbon
    {
        return Carbon::now()->subDays(Other::customerFeedbackRequestDelayDays($tenantId) + 7);
    }

    public function isFeedbackWindowOpen(Order $order, ?int $tenantId): bool
    {
        if ((int) $order->service_status !== OrderStatus::DELIVERED || ! $order->delivery_date) {
            return false;
        }

        if ($order->customer_feedback_submitted_at || $order->customer_feedback_request_expired_at) {
            return false;
        }

        return $order->delivery_date->lte($this->customerFeedbackRequestThreshold($tenantId))
            && $order->delivery_date->gt($this->customerFeedbackExpirationThreshold($tenantId));
    }
}
