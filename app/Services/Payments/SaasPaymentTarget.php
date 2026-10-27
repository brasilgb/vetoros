<?php

namespace App\Services\Payments;

use App\Models\Admin\Plan;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * O que está sendo pago: a cobrança em aberto do contrato personalizado (uma por competência) ou
 * a renovação do plano tradicional. Valor como decimal string com 2 casas, nunca float.
 */
final class SaasPaymentTarget
{
    private function __construct(
        public readonly Tenant $tenant,
        public readonly ?TenantSubscriptionCharge $charge,
        public readonly ?Plan $plan,
        public readonly string $amount,
        public readonly string $description,
        public readonly Carbon $dueDate,
    ) {}

    public static function forCharge(TenantSubscriptionCharge $charge): self
    {
        $charge->loadMissing('tenant', 'contract');

        return new self(
            $charge->tenant,
            $charge,
            null,
            number_format((float) $charge->amount, 2, '.', ''),
            'VetorOS - contrato '.$charge->contract->contract_number.' - competência '.$charge->cycle_start->format('d/m/Y').' a '.$charge->cycle_end->format('d/m/Y'),
            $charge->due_date->copy(),
        );
    }

    public static function forPlan(Tenant $tenant, Plan $plan): self
    {
        return new self(
            $tenant,
            null,
            $plan,
            number_format((float) $plan->value, 2, '.', ''),
            'VetorOS - assinatura '.$plan->name,
            now()->startOfDay(),
        );
    }

    public function cents(): int
    {
        [$integer, $fraction] = array_pad(explode('.', $this->amount), 2, '00');

        return (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** Chave da trava que serializa a criação de transações do mesmo alvo. */
    public function lockKey(): string
    {
        return $this->charge
            ? 'saas-payment:charge:'.$this->charge->id
            : 'saas-payment:plan:'.$this->tenant->id;
    }

    public function intervalMonths(): int
    {
        return $this->charge ? (int) $this->charge->contract->billing_months : $this->plan->billingMonths();
    }
}
