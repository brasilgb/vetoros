<?php

namespace App\Models\App;

use App\Models\Tenant;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AccountReceivable extends Model
{
    use HasFactory, Tenantable;

    protected $table = 'accounts_receivable';

    public const SOURCE_ORDER = 'order';
    public const SOURCE_SALE = 'sale';
    public const SOURCE_MAINTENANCE_CONTRACT = 'maintenance_contract';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'source_type',
        'source_id',
        'description',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'due_date',
        'competence_start',
        'competence_end',
        'fiscal_scheduled_for',
        'fiscal_queued_at',
        'status',
        'payment_method',
        'installment_number',
        'installments_total',
        'last_paid_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'due_date' => 'date',
        'competence_start' => 'date',
        'competence_end' => 'date',
        'fiscal_scheduled_for' => 'date',
        'fiscal_queued_at' => 'datetime',
        'last_paid_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountReceivablePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function fiscalDocuments(): MorphMany
    {
        return $this->morphMany(FiscalDocument::class, 'documentable');
    }

    public function isMaintenanceContract(): bool
    {
        return $this->source_type === self::SOURCE_MAINTENANCE_CONTRACT;
    }

    /** Contrato de origem (só para cobranças de contrato), sem depender do escopo de tenant da sessão. */
    public function maintenanceContract(): ?MaintenanceContract
    {
        return $this->isMaintenanceContract()
            ? MaintenanceContract::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->find($this->source_id)
            : null;
    }
}
