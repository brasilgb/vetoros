<?php

namespace App\Console\Commands;

use App\Services\Payments\SaasPaymentService;
use Illuminate\Console\Command;

class ReconcileSaasPayments extends Command
{
    protected $signature = 'vetoros:reconcile-saas-payments';

    protected $description = 'Efí: concilia as transações pendentes das assinaturas SaaS consultando a API (pagamento atrasado, webhook perdido, expiração)';

    public function handle(SaasPaymentService $payments): int
    {
        $checked = $payments->reconcilePending();

        $this->info("Transações Efí conciliadas: {$checked}.");

        return self::SUCCESS;
    }
}
