<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MaintenanceContract extends Model
{
    use HasFactory, Tenantable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Competência faturada pelo ciclo: o mês do vencimento ou o mês anterior (serviço pós-pago). */
    public const COMPETENCE_DUE_MONTH = 'due_month';

    public const COMPETENCE_PREVIOUS_MONTH = 'previous_month';

    public const COMPETENCES = [self::COMPETENCE_DUE_MONTH, self::COMPETENCE_PREVIOUS_MONTH];

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
        'invoice_competence',
        'auto_issue_enabled_at',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_billing_date' => 'date',
        'next_schedule_date' => 'date',
        'auto_issue_invoice' => 'boolean',
        'auto_send_invoice' => 'boolean',
        'auto_issue_enabled_at' => 'datetime',
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

    /**
     * Período de prestação faturado por um ciclo com este vencimento. Competência não é vencimento:
     * o padrão é o mês do vencimento; contratos pós-pagos faturam o mês anterior.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function competenceFor(CarbonInterface $dueDate): array
    {
        $month = Carbon::parse($dueDate)->startOfMonth();

        if ($this->invoice_competence === self::COMPETENCE_PREVIOUS_MONTH) {
            $month = $month->subMonthNoOverflow();
        }

        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * Data em que a NFS-e do ciclo deve ser solicitada. Padrão: o vencimento. É o único ponto a
     * mudar quando a legislação do município ou a contabilidade exigir outra data (por exemplo,
     * fim da competência); o vencimento não é, por definição, a data legal de emissão.
     */
    public function fiscalScheduleFor(CarbonInterface $dueDate): Carbon
    {
        return Carbon::parse($dueDate)->startOfDay();
    }

    /** Cobranças geradas pelo contrato (contas a receber de origem `maintenance_contract`). */
    public function receivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class, 'source_id')
            ->where('source_type', AccountReceivable::SOURCE_MAINTENANCE_CONTRACT);
    }
}
