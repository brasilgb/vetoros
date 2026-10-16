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
            'active' => 'boolean',
            'deactivated_at' => 'datetime',
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

    public function periodicityLabel(): string
    {
        return self::PERIODICITIES[$this->billing_months] ?? "{$this->billing_months} meses";
    }

    /** Ciclo iniciado no vencimento: [início, fim] com fim inclusivo. */
    public function cycleFor(CarbonInterface $dueDate): array
    {
        $start = Carbon::parse($dueDate)->startOfDay();

        return [$start, $start->copy()->addMonthsNoOverflow($this->billing_months)->subDay()];
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
