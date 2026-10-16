<?php

namespace App\Models\Admin;

use App\Models\App\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** Cobrança de um ciclo do contrato personalizado do SaaS (uma por ciclo). */
class TenantSubscriptionCharge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Em aberto',
        self::STATUS_PAID => 'Paga',
        self::STATUS_REFUNDED => 'Estornada',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cycle_start' => 'date',
            'cycle_end' => 'date',
            'due_date' => 'date',
            'fiscal_scheduled_for' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fiscal_queued_at' => 'datetime',
            'invoice_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TenantCommercialContract::class, 'tenant_commercial_contract_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(AdminFiscalDocument::class)->latest('id');
    }

    public function latestFiscalDocument(): HasOne
    {
        return $this->hasOne(AdminFiscalDocument::class)->latestOfMany();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TenantSubscriptionChargeDelivery::class)->latest('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Próximo dia depois do ciclo: até quando o pagamento cobre o acesso. */
    public function coverageUntil(): Carbon
    {
        return $this->cycle_end->copy()->addDay()->startOfDay();
    }
}
