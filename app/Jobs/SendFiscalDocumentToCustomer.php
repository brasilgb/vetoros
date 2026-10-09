<?php

namespace App\Jobs;

use App\Models\App\AccountReceivable;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Services\Fiscal\FiscalDocumentDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envio automático da NFS-e autorizada de cobrança de contrato (VETOR-FISCAL-05). Só envia se o
 * contrato pedir e se ainda não houver envio automático bem-sucedido. Falhas ficam registradas
 * e não são repetidas sozinhas (reenvio é manual), para não duplicar e-mails.
 */
class SendFiscalDocumentToCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $fiscalDocumentId, public readonly string $origin = FiscalDocumentDelivery::ORIGIN_AUTOMATIC) {}

    public function handle(FiscalDocumentDeliveryService $deliveries): void
    {
        $document = FiscalDocument::query()->withoutGlobalScopes()->find($this->fiscalDocumentId);

        if (! $document || $document->status !== FiscalDocument::STATUS_AUTHORIZED || $document->documentable_type !== AccountReceivable::class) {
            return;
        }

        $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($document->documentable_id);

        if (! $receivable?->maintenanceContract()?->auto_send_invoice || $deliveries->hasSuccessfulAutomaticDelivery($document)) {
            return;
        }

        $deliveries->send($document, $this->origin);
    }
}
