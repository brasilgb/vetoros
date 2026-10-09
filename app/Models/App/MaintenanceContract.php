<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceContract extends Model
{
    use HasFactory, Tenantable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'contract_number',
        'description',
        'monthly_amount',
        'billing_day',
        'start_date',
        'duration_months',
        'end_date',
        'visit_frequency_days',
        'preferred_technician_id',
        'next_billing_date',
        'next_schedule_date',
        'status',
        'notes',
        'created_by',
        'auto_issue_invoice',
        'auto_send_invoice',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_billing_date' => 'date',
        'next_schedule_date' => 'date',
        'auto_issue_invoice' => 'boolean',
        'auto_send_invoice' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function preferredTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_technician_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceContractLog::class)->latest();
    }

    /** Cobranças geradas pelo contrato (contas a receber de origem `maintenance_contract`). */
    public function receivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class, 'source_id')
            ->where('source_type', AccountReceivable::SOURCE_MAINTENANCE_CONTRACT);
    }
}
