<?php

namespace App\Services\Payments;

use App\Models\App\Payment;
use Illuminate\Support\Collection;

/**
 * Inventário somente leitura das transações de provedores anteriores à Efí (VETOR-SAAS-EFI-03).
 * Nada é alterado, recriado na Efí nem cancelado: os registros ficam como histórico, com o
 * `gateway`, os ids externos, valores, datas e a resposta original preservados.
 */
class LegacyPaymentsReport
{
    /** Status do provedor anterior agrupados para leitura. */
    private const GROUPS = [
        'quitados' => ['approved'],
        'pendentes' => ['pending', 'in_process', 'in_mediation', 'authorized'],
        'estornados' => ['refunded'],
        'contestados' => ['charged_back'],
        'cancelados_ou_recusados' => ['cancelled', 'rejected', 'expired'],
    ];

    public function summary(): array
    {
        $rows = Payment::query()
            ->where('gateway', '!=', Payment::GATEWAY_EFI)
            ->selectRaw('gateway, status, count(*) as total, sum(amount) as amount')
            ->groupBy('gateway', 'status')
            ->get();

        $groups = collect(self::GROUPS)->map(function (array $statuses) use ($rows) {
            $matching = $rows->whereIn('status', $statuses);

            return ['count' => (int) $matching->sum('total'), 'amount' => number_format((float) $matching->sum('amount'), 2, '.', '')];
        })->all();

        $known = collect(self::GROUPS)->flatten()->all();
        $other = $rows->reject(fn ($row) => in_array($row->status, $known, true));
        $groups['outros'] = ['count' => (int) $other->sum('total'), 'amount' => number_format((float) $other->sum('amount'), 2, '.', '')];

        return [
            'gateways' => $rows->pluck('gateway')->unique()->values()->all(),
            'total' => (int) $rows->sum('total'),
            'groups' => $groups,
            'pending_valid' => $this->pendingValid()->count(),
            'pending_expired' => $this->pendingExpired()->count(),
        ];
    }

    /** Pendentes ainda dentro da validade: podem ser pagos e exigem conciliação manual antes da implantação. */
    public function pendingValid(): Collection
    {
        return $this->pending()->where('expires_at', '>', now())->get();
    }

    /** Pendentes vencidos: o provedor não aceita mais pagamento; ficam como histórico. */
    public function pendingExpired(): Collection
    {
        return $this->pending()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '<=', now()))->get();
    }

    private function pending()
    {
        return Payment::query()
            ->where('gateway', '!=', Payment::GATEWAY_EFI)
            ->whereIn('status', self::GROUPS['pendentes'])
            ->orderBy('id');
    }
}
