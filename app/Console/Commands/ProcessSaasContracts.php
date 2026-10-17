<?php

namespace App\Console\Commands;

use App\Services\Commercial\SaasContractService;
use Illuminate\Console\Command;

class ProcessSaasContracts extends Command
{
    protected $signature = 'vetoros:process-saas-contracts';

    protected $description = 'Contratos SaaS personalizados: aplica as voltas ao plano agendadas, gera as cobranças do ciclo, envia faturas e enfileira a NFS-e programada';

    public function handle(SaasContractService $contracts): int
    {
        // Voltas ao plano com data efetiva chegada saem antes da geração: não recebem cobrança nova do contrato.
        $reverted = $contracts->applyScheduledReverts();
        $generated = $contracts->generateDueCharges();
        $resent = $contracts->retryPendingInvoices();
        // Emissão fiscal programada (independente do pagamento), depois de gerar as cobranças e as faturas do dia.
        $queued = $contracts->queueScheduledInvoices();

        $this->info("Voltas ao plano aplicadas: {$reverted}. Cobranças geradas: {$generated}. Faturas reenviadas: {$resent}. NFS-e enfileiradas: {$queued}.");

        return self::SUCCESS;
    }
}
