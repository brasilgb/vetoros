<?php

namespace App\Jobs;

use App\Models\App\AccountReceivable;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContract;
use App\Models\App\MaintenanceContractLog;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\MaintenanceContractService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emissão automática da NFS-e programada de um ciclo de contrato (VETOR-FISCAL-05.3), na data
 * fiscal do ciclo e independente do pagamento: a cobrança continua em aberto até o recebimento.
 *
 * Idempotente: um ciclo que já tem qualquer documento fiscal não é emitido de novo (rejeição e
 * falha seguem para reprocessamento manual), e a reserva do NativeFiscalService trava a cobrança
 * e recusa uma segunda nota em processamento ou autorizada; jobs repetidos ou concorrentes viram no-op.
 * Dados fiscais inválidos e recusas definitivas ficam no histórico do contrato, sem nova tentativa.
 * Indisponibilidade da Spedy mantém a nota "em processamento" e a reconciliação conclui.
 */
class EmitMaintenanceContractInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $receivableId, public readonly ?int $userId = null) {}

    public function handle(NativeFiscalService $fiscal, MaintenanceContractService $contracts): void
    {
        $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($this->receivableId);
        $contract = $receivable?->maintenanceContract();

        if (! $receivable || ! $contract) {
            return;
        }

        if (FiscalDocument::query()->withoutGlobalScopes()
            ->where('documentable_type', AccountReceivable::class)
            ->where('documentable_id', $receivable->id)
            ->exists()) {
            return;
        }

        if (! $contracts->isAutomaticallyInvoiceable($contract, $receivable)) {
            $this->log($receivable, 'invoice_skipped', ['reason' => match (true) {
                ! $contract->auto_issue_invoice => 'emissão automática desligada',
                $contract->status !== MaintenanceContract::STATUS_ACTIVE => 'contrato não está ativo',
                $receivable->status === AccountReceivable::STATUS_CANCELLED => 'cobrança cancelada',
                default => 'ciclo fora da programação fiscal automática',
            }]);

            return;
        }

        try {
            $fiscal->emitForContractReceivable($receivable, $this->userId, requirePaid: false);
        } catch (FiscalValidationException $exception) {
            $this->log($receivable, 'invoice_failed', ['error' => $exception->getMessage()]);
        } catch (FiscalEmissionException $exception) {
            // Outra execução já reservou ou autorizou a nota desta cobrança: nada a fazer.
            if (! FiscalDocument::hasActiveNativeFor($receivable)) {
                $this->log($receivable, 'invoice_failed', ['error' => $exception->getMessage()]);
            }
        } catch (SpedyException $exception) {
            $this->log($receivable, 'invoice_failed', ['error' => $exception->getMessage()]);
        }
    }

    private function log(AccountReceivable $receivable, string $action, array $data): void
    {
        MaintenanceContractLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $receivable->tenant_id,
            'maintenance_contract_id' => $receivable->source_id,
            'user_id' => $this->userId,
            'action' => $action,
            'data' => ['account_receivable_id' => $receivable->id, ...$data],
        ]);
    }
}
