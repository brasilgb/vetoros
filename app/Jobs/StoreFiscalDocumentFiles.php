<?php

namespace App\Jobs;

use App\Models\App\FiscalDocument;
use App\Services\Fiscal\NativeFiscalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Baixa da Spedy o XML e o PDF de uma nota autorizada/cancelada e guarda no
 * disco privado `fiscal`. Fora do webhook para responder 2xx rapidamente.
 */
class StoreFiscalDocumentFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $fiscalDocumentId) {}

    public function handle(NativeFiscalService $service): void
    {
        $document = FiscalDocument::query()->withoutGlobalScopes()->find($this->fiscalDocumentId);

        if ($document) {
            $service->storeFiles($document);
        }
    }
}
