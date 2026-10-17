<?php

namespace App\Console\Commands;

use App\Services\Payments\LegacyPaymentsReport;
use Illuminate\Console\Command;

/**
 * Procedimento de transição (VETOR-SAAS-EFI-03): mostra o histórico de provedores anteriores e as
 * pendências que precisam de conciliação manual. Somente leitura; retorna falha enquanto houver
 * pendente ainda válido, para servir de trava antes da implantação.
 */
class LegacyPaymentsReportCommand extends Command
{
    protected $signature = 'vetoros:legacy-payments-report';

    protected $description = 'Somente leitura: inventário das transações de provedores anteriores à Efí e pendências a conciliar';

    public function handle(LegacyPaymentsReport $report): int
    {
        $summary = $report->summary();

        $this->info('Provedores anteriores no histórico: '.($summary['gateways'] === [] ? 'nenhum' : implode(', ', $summary['gateways'])).". Registros: {$summary['total']}.");
        $this->table(['Grupo', 'Quantidade', 'Valor'], collect($summary['groups'])->map(fn ($group, $name) => [$name, $group['count'], $group['amount']])->values()->all());

        $valid = $report->pendingValid();
        if ($valid->isNotEmpty()) {
            $this->warn('Pendentes ainda válidos (podem ser pagos): confira cada um no painel do provedor e, se pago, registre a quitação manual no RootAdmin.');
            $this->table(['ID local', 'Tenant', 'ID externo', 'Valor', 'Validade'], $valid->map(fn ($payment) => [
                $payment->id, $payment->tenant_id, $payment->payment_id, (string) $payment->amount, $payment->expires_at?->format('d/m/Y H:i'),
            ])->all());

            return self::FAILURE;
        }

        $this->info("Nenhum pendente válido. Pendentes vencidos (só histórico): {$summary['pending_expired']}.");

        return self::SUCCESS;
    }
}
