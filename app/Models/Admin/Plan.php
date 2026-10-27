<?php

namespace App\Models\Admin;

use App\Models\Tenant;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'value',
        'description',
        'billing_months',
        'is_active',
        'is_public',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean'];
    }

    public function availableForSelfService(): bool
    {
        return $this->is_active && $this->is_public && ! $this->isTrial() && ! $this->isCourtesy()
            && (float) $this->value > 0 && $this->billingMonths() > 0;
    }

    public function isTrial(): bool
    {
        $text = mb_strtolower(trim(($this->slug ?? '').' '.($this->name ?? '')));

        return str_contains($text, 'trial');
    }

    public function isCourtesy(): bool
    {
        $text = mb_strtolower(trim(($this->slug ?? '').' '.($this->name ?? '')));

        return str_contains($text, 'cortesia') || str_contains($text, 'courtesy');
    }

    public function isTrialCycle(): bool
    {
        return $this->isTrial() || (int) ($this->billing_months ?? 0) === 14;
    }

    public function billingMonths(): int
    {
        $periodMonths = $this->preferredPeriodBillingMonths();
        if ($periodMonths > 0) {
            return $periodMonths;
        }

        if ((int) ($this->billing_months ?? 0) === 14) {
            return 0;
        }

        if ((int) ($this->billing_months ?? 0) > 0) {
            return (int) $this->billing_months;
        }

        $text = mb_strtolower(trim(($this->slug ?? '').' '.($this->name ?? '')));
        if ($text === '') {
            return 0;
        }

        if (str_contains($text, 'anual') || str_contains($text, 'year')) {
            return 12;
        }
        if (str_contains($text, 'semestral') || str_contains($text, 'semiannual')) {
            return 6;
        }
        if (str_contains($text, 'trimestral') || str_contains($text, 'quarter')) {
            return 3;
        }
        if (str_contains($text, 'mensal') || str_contains($text, 'month')) {
            return 1;
        }

        if (preg_match('/(^|[^0-9])(12|6|3|1)($|[^0-9])/', $text, $matches) === 1) {
            return (int) $matches[2];
        }

        return 0;
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }

    public function preferredPeriod(): ?Period
    {
        $periods = $this->relationLoaded('periods')
            ? $this->periods
            : $this->periods()->get();

        if ($periods->isEmpty()) {
            return null;
        }

        return $periods->first(function (Period $period) {
            return $period->billingMonths() === (int) ($this->billing_months ?? 0);
        }) ?? $periods->sortBy('id')->first();
    }

    public function preferredPeriodBillingMonths(): int
    {
        $preferred = $this->preferredPeriod();

        return $preferred ? $preferred->billingMonths() : 0;
    }

    protected static function newFactory(): Factory
    {
        return PlanFactory::new();
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'plan');
    }
}
