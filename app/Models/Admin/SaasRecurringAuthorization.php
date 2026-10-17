<?php

namespace App\Models\Admin;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recorrência no cartão autorizada pelo titular (assinatura nativa da Efí). Enquanto ativa, o
 * agendador não cria outra cobrança externa: cada cobrança da Efí quita a competência local.
 * Só a máscara do cartão é guardada; número, CVV e validade ficam na Efí.
 */
class SaasRecurringAuthorization extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interval_months' => 'integer',
            'authorized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public static function activeFor(int $tenantId): ?self
    {
        return static::query()->where('tenant_id', $tenantId)->where('status', self::STATUS_ACTIVE)->latest('id')->first();
    }
}
