<?php

namespace App\Jobs;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalDocumentDelivery;
use App\Services\Commercial\SaasContractService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Comunicação fiscal automática depois da autorização da NFS-e do SaaS. Só envia nota autorizada
 * e uma única vez com sucesso; falha de SMTP fica registrada e não afeta a nota.
 */
class SendSaasFiscalNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $documentId) {}

    public function handle(SaasContractService $contracts): void
    {
        $document = AdminFiscalDocument::query()->find($this->documentId);

        if (! $document || AdminFiscalDocumentDelivery::query()
            ->where('admin_fiscal_document_id', $document->id)
            ->where('status', AdminFiscalDocumentDelivery::STATUS_SENT)
            ->exists()) {
            return;
        }

        $contracts->sendFiscalNotice($document);
    }
}
