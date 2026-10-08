<?php

namespace App\Services;

use App\Models\App\OrderPayment;
use App\Models\App\PaymentFeeSetting;
use Illuminate\Validation\ValidationException;

/**
 * Resolve a taxa de um pagamento no momento do registro, nesta ordem:
 * 1. valor informado manualmente (inclusive zero)     → fee_source = manual;
 * 2. configuração do meio de pagamento do tenant       → fee_source = config;
 * 3. nenhum dos dois                                    → taxa e líquido null (desconhecidos).
 *
 * Nunca presume taxa. Taxa zero só existe quando informada ou configurada como zero.
 */
class PaymentFeeService
{
    /**
     * @return array{fee_amount: float|null, fee_source: string|null, fee_percentage: float|null, fee_fixed_amount: float|null, net_amount: float|null}
     */
    public function resolve(int $tenantId, string $paymentMethod, float $amount, ?float $manualFee = null): array
    {
        if ($manualFee !== null) {
            if ($manualFee < 0 || $manualFee > $amount) {
                throw ValidationException::withMessages([
                    'fee_amount' => 'A taxa deve estar entre zero e o valor do pagamento.',
                ]);
            }

            return $this->result(round($manualFee, 2), OrderPayment::FEE_SOURCE_MANUAL, null, null, $amount);
        }

        // Tenant explícito (não depende da sessão): a configuração é sempre da empresa da OS.
        $setting = PaymentFeeSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('payment_method', $paymentMethod)
            ->first();

        if (! $setting) {
            return ['fee_amount' => null, 'fee_source' => null, 'fee_percentage' => null, 'fee_fixed_amount' => null, 'net_amount' => null];
        }

        $percentage = round((float) $setting->fee_percentage, 3);
        $fixed = round((float) $setting->fee_fixed_amount, 2);
        // A taxa nunca supera o valor pago (taxa fixa em pagamento pequeno).
        $fee = min($amount, round($amount * $percentage / 100 + $fixed, 2));

        return $this->result($fee, OrderPayment::FEE_SOURCE_CONFIG, $percentage, $fixed, $amount);
    }

    /**
     * @return array{fee_amount: float, fee_source: string, fee_percentage: float|null, fee_fixed_amount: float|null, net_amount: float}
     */
    private function result(float $fee, string $source, ?float $percentage, ?float $fixed, float $amount): array
    {
        return [
            'fee_amount' => $fee,
            'fee_source' => $source,
            'fee_percentage' => $percentage,
            'fee_fixed_amount' => $fixed,
            'net_amount' => round($amount - $fee, 2),
        ];
    }
}
