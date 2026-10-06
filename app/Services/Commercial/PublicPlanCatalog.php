<?php

namespace App\Services\Commercial;

use App\Models\Admin\Plan;
use Illuminate\Support\Collection;

/**
 * Planos exibidos no site público. Fonte única: a tabela `plans`, administrada
 * pelo RootAdmin. Enquanto `commercial.public_prices_enabled` estiver
 * desligado, nenhum valor sai daqui (nem em HTML, nem nas props Inertia).
 */
class PublicPlanCatalog
{
    /** Periodicidades oferecidas publicamente, por número de meses de cobrança. */
    private const PERIODICITIES = [
        1 => ['name' => 'Mensal', 'periodicity' => 'mensal', 'description' => 'Plano com contratação mensal'],
        6 => ['name' => 'Semestral', 'periodicity' => 'semestral', 'description' => 'Plano com contratação semestral'],
        12 => ['name' => 'Anual', 'periodicity' => 'anual', 'description' => 'Plano com contratação anual', 'popular' => true],
    ];

    public static function pricesEnabled(): bool
    {
        return (bool) config('commercial.public_prices_enabled', false);
    }

    /** @return list<array{name: string, periodicity: string, description: string, popular: bool, price_label?: string}> */
    public function plans(): array
    {
        $showPrices = self::pricesEnabled();
        $stored = $showPrices ? $this->storedPlans() : collect();

        return collect(self::PERIODICITIES)
            ->map(function (array $definition, int $months) use ($showPrices, $stored) {
                $plan = [
                    'name' => $definition['name'],
                    'periodicity' => $definition['periodicity'],
                    'description' => $definition['description'],
                    'popular' => (bool) ($definition['popular'] ?? false),
                ];

                $value = $stored->get($months)?->value;

                if ($showPrices && $value !== null && (float) $value > 0) {
                    $plan['price_label'] = 'R$ '.number_format((float) $value, 2, ',', '.');
                }

                return $plan;
            })
            ->values()
            ->all();
    }

    /** @return Collection<int, Plan> planos cobráveis indexados pelos meses de cobrança */
    private function storedPlans(): Collection
    {
        return Plan::query()
            ->whereIn('billing_months', array_keys(self::PERIODICITIES))
            ->orderBy('id')
            ->get()
            ->reject(fn (Plan $plan) => $plan->isTrial() || $plan->isCourtesy())
            ->unique('billing_months')
            ->keyBy('billing_months');
    }
}
