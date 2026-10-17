<?php

namespace App\Models\Admin;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Condições comerciais personalizadas do tenant (VETOR-SAAS-CONTRATO-01), mantidas só pelo RootAdmin.
 * `amount` é o valor total de cada ciclo de `billing_months` meses, nunca multiplicado.
 * Um registro por tenant: ao voltar ao plano tradicional fica inativo, com histórico e cobranças.
 * `anchor_day` é o dia contratual do vencimento (VETOR-SAAS-CONTRATO-02); `revert_*` agenda a volta ao plano.
 */
class TenantCommercialContract extends Model
{
    public const PERIODICITIES = [1 => 'Mensal', 6 => 'Semestral', 12 => 'Anual'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'billing_months' => 'integer',
            'start_date' => 'date',
            'next_due_date' => 'date',
            'anchor_day' => 'integer',
            'active' => 'boolean',
            'deactivated_at' => 'datetime',
            'revert_scheduled_for' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TenantSubscriptionCharge::class);
    }

    public function revertPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'revert_plan_id');
    }

    public function revertPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'revert_period_id');
    }

    public function periodicityLabel(): string
    {
        return self::PERIODICITIES[$this->billing_months] ?? "{$this->billing_months} meses";
    }

    /** Ciclo iniciado no vencimento: [início, fim] com fim inclusivo; o fim é a véspera do próximo vencimento. */
    public function cycleFor(CarbonInterface $dueDate): array
    {
        $start = Carbon::parse($dueDate)->startOfDay();

        return [$start, $this->nextDueAfter($start)->subDay()];
    }

    /**
     * Vencimento seguinte mantendo o dia contratual: com dia 31, 31/01 → 28/02 → 31/03 (o mês curto
     * não desloca os ciclos seguintes). Sem âncora gravada vale o dia do próprio vencimento.
     */
    public function nextDueAfter(CarbonInterface $dueDate): Carbon
    {
        $next = Carbon::parse($dueDate)->startOfDay()->addMonthsNoOverflow($this->billing_months);
        $day = $this->anchor_day ?: Carbon::parse($dueDate)->day;

        return $next->day(min($day, $next->daysInMonth));
    }

    /**
     * Data em que a NFS-e do ciclo deve ser solicitada. Padrão: o vencimento. É o único ponto a
     * mudar quando o município ou a contabilidade exigirem outra data; não depende do pagamento.
     */
    public function fiscalScheduleFor(CarbonInterface $dueDate): Carbon
    {
        return Carbon::parse($dueDate)->startOfDay();
    }
}
