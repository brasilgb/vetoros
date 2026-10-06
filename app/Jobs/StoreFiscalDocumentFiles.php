<?php

namespace App\Jobs;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\App\FiscalDocument;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\SaasInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Baixa da Spedy o XML e o PDF de uma nota autorizada/cancelada e guarda no
 * disco privado `fiscal`. Fora do webhook para responder 2xx rapidamente.
 * `saas`: nota da própria plataforma (AdminFiscalDocument), guardada à parte.
 */
class StoreFiscalDocumentFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $fiscalDocumentId, public readonly bool $saas = false) {}

    public function handle(NativeFiscalService $native, SaasInvoiceService $saas): void
    {
        if ($this->saas) {
            $document = AdminFiscalDocument::query()->find($this->fiscalDocumentId);
            $document && $saas->storeFiles($document);

            return;
        }

        $document = FiscalDocument::query()->withoutGlobalScopes()->find($this->fiscalDocumentId);
        $document && $native->storeFiles($document);
    }
}
