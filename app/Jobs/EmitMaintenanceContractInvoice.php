<?php

namespace App\Jobs;

use App\Models\App\AccountReceivable;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContractLog;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emissão automática da NFS-e de uma cobrança de contrato quitada (VETOR-FISCAL-05).
 *
 * Idempotente: a reserva do NativeFiscalService trava a cobrança e recusa uma segunda nota
 * enquanto houver uma em processamento ou autorizada; jobs repetidos ou concorrentes viram no-op.
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

    public function handle(NativeFiscalService $fiscal): void
    {
        $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($this->receivableId);
        $contract = $receivable?->maintenanceContract();

        if (! $receivable || ! $contract) {
            return;
        }

        if (! $contract->auto_issue_invoice || $receivable->status !== AccountReceivable::STATUS_PAID) {
            $this->log($receivable, 'invoice_skipped', ['reason' => ! $contract->auto_issue_invoice ? 'emissão automática desligada' : 'cobrança não quitada']);

            return;
        }

        try {
            $fiscal->emitForContractReceivable($receivable, $this->userId);
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
