<?php

namespace App\Console\Commands;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Console\Command;

/**
 * Webhooks são best-effort: reconcilia as notas que continuam em processamento
 * ou contingência consultando a Spedy (a consulta não aciona a SEFAZ).
 */
class SyncSpedyFiscalDocuments extends Command
{
    protected $signature = 'fiscal:sync-spedy {--limit=50 : Máximo de notas por execução}';

    protected $description = 'Atualiza o status das notas fiscais nativas pendentes na Spedy';

    /** Sem confirmação após este prazo, a nota volta a permitir novo envio (mesmo integrationId). */
    private const UNCONFIRMED_AFTER_MINUTES = 30;

    public function handle(NativeFiscalService $service, SaasInvoiceService $saas): int
    {
        if (! SpedyClient::isConfigured()) {
            $this->info('Spedy não configurada; nada a sincronizar.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $clients = FiscalDocument::query()->withoutGlobalScopes()
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->whereIn('status', FiscalDocument::PENDING_STATUSES)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        // Notas do SaaS (emitente da plataforma), em tabela separada.
        $platform = AdminFiscalDocument::query()
            ->where('provider', 'spedy')
            ->whereIn('status', FiscalDocument::PENDING_STATUSES)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $updated = $this->reconcile($clients, fn ($document) => $service->refresh($document))
            + $this->reconcile($platform, fn ($document) => $saas->refresh($document));

        $this->info('Notas verificadas: '.($clients->count() + $platform->count())."; status alterado: {$updated}.");

        return self::SUCCESS;
    }

    private function reconcile(iterable $documents, callable $refresh): int
    {
        $updated = 0;

        foreach ($documents as $document) {
            try {
                $before = $document->status;
                $document = $refresh($document);

                if ($document->status === FiscalDocument::STATUS_PROCESSING
                    && blank($document->provider_reference)
                    // submitted_at, não updated_at: a própria rotação da fila atualiza o updated_at.
                    && ($document->submitted_at ?? $document->created_at)->lte(now()->subMinutes(self::UNCONFIRMED_AFTER_MINUTES))) {
                    $document->forceFill([
                        'status' => FiscalDocument::STATUS_FAILED,
                        'error_message' => 'Envio não confirmado pelo serviço de emissão. Emita novamente.',
                    ])->save();
                } else {
                    // Atualiza o carimbo para a fila girar entre as notas pendentes.
                    $document->touch();
                }

                $updated += $before !== $document->status ? 1 : 0;
            } catch (SpedyException $exception) {
                $this->warn('Nota '.class_basename($document)." #{$document->id}: {$exception->getMessage()}");
            }
        }

        return $updated;
    }
}
