<?php

namespace App\Services\Fiscal;

use App\Jobs\SendFiscalDocumentToCustomer;
use App\Jobs\StoreFiscalDocumentFiles;
use App\Models\App\AccountReceivable;
use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Models\App\FiscalSetting;
use App\Models\App\MaintenanceContractLog;
use App\Models\App\Order;
use App\Models\App\Sale;
use App\Models\Tenant;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyInvoiceState;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Emissão fiscal nativa (NF-e, NFC-e e NFS-e) via Spedy.
 *
 * A emissão é assíncrona: o POST só confirma o recebimento. O resultado chega
 * pelo webhook (SpedyWebhookController) ou pela reconciliação periódica
 * (fiscal:sync-spedy). O integrationId é estável por registro e tentativa,
 * então reenvios por timeout não duplicam a nota.
 */
class NativeFiscalService
{
    public function __construct(private readonly SpedyPayloadBuilder $payloads) {}

    public function blocker(int $tenantId, string $model): ?string
    {
        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $tenantAllowed = (bool) Tenant::query()->whereKey($tenantId)->value('automatic_fiscal_emission_enabled');

        return $setting
            ? $setting->nativeEmissionBlocker($model, $tenantAllowed)
            : (new FiscalSetting)->nativeEmissionBlocker($model, $tenantAllowed);
    }

    /**
     * O que impede a NFS-e automática dos contratos do tenant (VETOR-FISCAL-05): as mesmas
     * camadas de liberação da NFS-e e os dados tributários que o payload exige.
     */
    public function contractInvoiceBlocker(int $tenantId): ?string
    {
        if ($blocker = $this->blocker($tenantId, SpedyClient::MODEL_NFSE)) {
            return $blocker;
        }

        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        return match (true) {
            blank($setting?->service_list_item) => 'Informe o item da lista de serviços (LC 116) nas configurações fiscais.',
            $setting?->default_iss_rate === null => 'Informe a alíquota de ISS nas configurações fiscais.',
            default => null,
        };
    }

    public function emitForSale(Sale $sale, string $model, ?int $userId): FiscalDocument
    {
        if (! in_array($model, [SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE], true)) {
            throw new \InvalidArgumentException('Vendas emitem NF-e ou NFC-e.');
        }
        if ($sale->status === 'cancelled') {
            throw new FiscalEmissionException('Não é possível emitir nota fiscal para venda cancelada.');
        }

        return $this->emit($sale, $model, $userId, function (FiscalSetting $setting, string $integrationId) use ($sale, $model) {
            $company = Company::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $sale->tenant_id]);

            return $model === SpedyClient::MODEL_NFE
                ? $this->payloads->productInvoice($sale, $company, $setting, $integrationId)
                : $this->payloads->consumerInvoice($sale, $setting, $integrationId);
        });
    }

    public function emitForOrder(Order $order, ?int $userId): FiscalDocument
    {
        return $this->emit($order, SpedyClient::MODEL_NFSE, $userId, fn (FiscalSetting $setting, string $integrationId) => $this->payloads->serviceInvoice($order, $setting, $integrationId));
    }

    /**
     * NFS-e de uma cobrança de contrato de manutenção quitada (VETOR-FISCAL-05). Usa a mesma
     * reserva com trava e o mesmo integrationId do emit(): cliques, jobs concorrentes e novas
     * tentativas não geram duas notas para a mesma cobrança.
     */
    public function emitForContractReceivable(AccountReceivable $receivable, ?int $userId): FiscalDocument
    {
        $receivable = AccountReceivable::query()->withoutGlobalScopes()->findOrFail($receivable->getKey());
        $contract = $receivable->maintenanceContract();

        if (! $contract) {
            throw new FiscalEmissionException('Esta cobrança não pertence a um contrato de manutenção.');
        }
        if ($receivable->status !== AccountReceivable::STATUS_PAID) {
            throw new FiscalEmissionException('A NFS-e do contrato só é emitida para cobrança integralmente quitada.');
        }

        return $this->emit($receivable, SpedyClient::MODEL_NFSE, $userId, fn (FiscalSetting $setting, string $integrationId) => $this->payloads->contractServiceInvoice($receivable, $contract, $setting, $integrationId));
    }

    /** Consulta a Spedy (sem acionar a SEFAZ) e aplica o status atual. */
    public function refresh(FiscalDocument $document): FiscalDocument
    {
        $this->assertNative($document);

        $client = $this->clientFor($document);

        if (blank($document->provider_reference)) {
            // Envio sem confirmação: procura pelo integrationId; se a Spedy nunca
            // recebeu, permanece processando até um novo envio.
            $invoice = filled($document->integration_id)
                ? $client->findInvoiceByIntegrationId($document->type, $document->integration_id)
                : null;

            return $invoice ? $this->applyInvoice($document, $invoice) : $document;
        }

        $invoice = $client->getInvoice($document->type, $document->provider_reference);

        return $this->applyInvoice($document, $invoice);
    }

    public function cancel(FiscalDocument $document, string $reason, ?int $userId): FiscalDocument
    {
        $this->assertNative($document);
        $reason = trim($reason);

        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            throw new FiscalEmissionException('Somente notas autorizadas podem ser canceladas.');
        }
        if (mb_strlen($reason) < 15 || mb_strlen($reason) > 255) {
            throw new FiscalEmissionException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $invoice = $this->clientFor($document)->cancelInvoice($document->type, $document->provider_reference, $reason);

        $document->forceFill(['cancel_reason' => $reason, 'notes' => $this->appendNote($document->notes, 'Cancelamento solicitado por usuário #'.$userId)])->save();

        return $this->applyInvoice($document, $invoice);
    }

    /** Conteúdo do PDF/XML: a cópia guardada no disco `fiscal`, ou a Spedy se ainda não guardada. */
    public function download(FiscalDocument $document, string $format): string
    {
        $this->assertNative($document);
        $format = $format === 'xml' ? 'xml' : 'pdf';

        if (! in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED, FiscalDocument::STATUS_CONTINGENCY], true)) {
            throw new FiscalEmissionException('Arquivo disponível somente para notas autorizadas.');
        }

        $path = $format === 'xml' ? $document->xml_path : $document->pdf_path;

        if (filled($path) && Storage::disk('fiscal')->exists($path)) {
            return (string) Storage::disk('fiscal')->get($path);
        }

        return $this->clientFor($document)->downloadInvoiceFile($document->type, $document->provider_reference, $format)->body();
    }

    /**
     * Guarda XML e PDF da nota no disco privado `fiscal` (guarda legal e
     * rastreabilidade). Após o cancelamento, a Spedy devolve o XML com o
     * evento, que substitui a cópia anterior.
     */
    public function storeFiles(FiscalDocument $document): FiscalDocument
    {
        $this->assertNative($document);

        if (! in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true) || blank($document->provider_reference)) {
            return $document;
        }

        $client = $this->clientFor($document);
        $base = sprintf('%d/%s/%s/%d-%s', $document->tenant_id, $document->type, ($document->issued_at ?? now())->format('Y/m'), $document->id, $document->status);
        $xml = $client->downloadInvoiceFile($document->type, $document->provider_reference, 'xml')->body();
        $pdf = $client->downloadInvoiceFile($document->type, $document->provider_reference, 'pdf')->body();

        Storage::disk('fiscal')->put("{$base}.xml", $xml);
        Storage::disk('fiscal')->put("{$base}.pdf", $pdf);

        $document->forceFill([
            'xml_path' => "{$base}.xml",
            'pdf_path' => "{$base}.pdf",
            'xml_sha256' => hash('sha256', $xml),
        ])->save();

        return $document;
    }

    /**
     * Aplica o objeto de nota da Spedy (resposta da API ou `data` do webhook).
     * Ignora regressões: um evento antigo de "processando" não desfaz uma
     * autorização ou um cancelamento já registrados.
     */
    public function applyInvoice(FiscalDocument $document, array $invoice): FiscalDocument
    {
        $providerStatus = (string) ($invoice['status'] ?? '');
        $status = SpedyInvoiceState::localStatus($providerStatus);

        return DB::transaction(function () use ($document, $invoice, $status) {
            $document = FiscalDocument::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);

            if ($status === null || SpedyInvoiceState::isRegression($document->status, $status)) {
                return $document;
            }

            $previousStatus = $document->status;

            $document->forceFill(SpedyInvoiceState::attributes($document, $invoice, $status))->save();

            $this->syncDocumentable($document);

            if (in_array($status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true)
                && ($previousStatus !== $status || blank($document->xml_path))) {
                StoreFiscalDocumentFiles::dispatch($document->id)->afterCommit();
            }

            // Depois do job de arquivos: o envio ao cliente usa a cópia guardada do PDF/XML.
            $this->trackContractReceivable($document, $previousStatus);

            return $document;
        });
    }

    private function emit(Model $documentable, string $model, ?int $userId, callable $buildPayload): FiscalDocument
    {
        $tenantId = (int) $documentable->tenant_id;

        if ($blocker = $this->blocker($tenantId, $model)) {
            throw new FiscalEmissionException($blocker);
        }

        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->firstOrFail();

        // Reserva o documento antes de chamar a Spedy. A trava no registro de
        // origem serializa cliques duplos; o integrationId único impede duas notas.
        $document = DB::transaction(function () use ($documentable, $model, $userId, $tenantId, $setting) {
            $locked = $documentable->newQuery()->withoutGlobalScopes()->whereKey($documentable->getKey())->lockForUpdate()->first();

            $existing = FiscalDocument::query()->withoutGlobalScopes()
                ->where('documentable_type', $documentable::class)
                ->where('documentable_id', $documentable->getKey())
                ->where('provider', FiscalSetting::PROVIDER_SPEDY)
                ->whereIn('status', FiscalDocument::BLOCKING_STATUSES)
                ->first();

            if (! $existing && filled($locked?->fiscal_document_number)) {
                throw new FiscalEmissionException('Este registro já possui comprovante fiscal registrado.');
            }

            if ($existing) {
                throw new FiscalEmissionException($existing->status === FiscalDocument::STATUS_AUTHORIZED
                    ? 'Este registro já possui nota fiscal autorizada.'
                    : 'Já existe uma nota fiscal em processamento para este registro.');
            }

            // Uma nota rejeitada é corrigida reenviando o mesmo integrationId (a Spedy
            // atualiza a nota em vez de criar outra).
            $retry = FiscalDocument::query()->withoutGlobalScopes()
                ->where('documentable_type', $documentable::class)
                ->where('documentable_id', $documentable->getKey())
                ->where('provider', FiscalSetting::PROVIDER_SPEDY)
                ->where('type', $model)
                ->whereIn('status', [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_FAILED])
                ->whereNotNull('integration_id')
                ->latest('id')
                ->first();

            $attributes = [
                'status' => FiscalDocument::STATUS_PROCESSING,
                'provider_status' => null,
                'error_message' => null,
                'requested_by' => $userId,
                'environment' => $setting->emission_environment,
            ];

            if ($retry) {
                $retry->forceFill($attributes)->save();

                return $retry;
            }

            return FiscalDocument::query()->create([
                ...$attributes,
                'tenant_id' => $tenantId,
                'documentable_type' => $documentable::class,
                'documentable_id' => $documentable->getKey(),
                'type' => $model,
                'provider' => FiscalSetting::PROVIDER_SPEDY,
                'integration_id' => (string) Str::uuid(),
            ]);
        });

        try {
            $payload = $buildPayload($setting, $document->integration_id);
        } catch (FiscalValidationException $exception) {
            $document->forceFill(['status' => FiscalDocument::STATUS_FAILED, 'error_message' => $exception->getMessage()])->save();

            throw $exception;
        }

        $document->forceFill(['request_payload' => SpedyInvoiceState::redactPayload($payload), 'submitted_at' => now()])->save();

        try {
            $invoice = SpedyClient::forCompany($setting->api_token)->createInvoice($model, $payload);
        } catch (SpedyException $exception) {
            // Falha transitória: a Spedy pode ter recebido a nota. Mantém "processando"
            // e a reconciliação/novo envio com o mesmo integrationId resolve.
            $document->forceFill([
                'status' => $exception->isTransient() ? FiscalDocument::STATUS_PROCESSING : FiscalDocument::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            if (! $exception->isTransient()) {
                throw $exception;
            }

            Log::warning('Spedy: emissão sem confirmação, aguardando reconciliação', ['fiscal_document_id' => $document->id]);

            return $document->refresh();
        }

        $document->forceFill(['provider_reference' => $invoice['id'] ?? $document->provider_reference])->save();

        return $this->applyInvoice($document, $invoice);
    }

    private function clientFor(FiscalDocument $document): SpedyClient
    {
        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $document->tenant_id)->firstOrFail();

        return SpedyClient::forCompany($setting->api_token);
    }

    private function assertNative(FiscalDocument $document): void
    {
        if (! $document->isNative()) {
            throw new FiscalEmissionException('Operação disponível apenas para notas emitidas pelo sistema.');
        }
    }

    /** Espelha a nota autorizada nos campos fiscais da venda/OS usados pelas telas e comprovantes. */
    private function syncDocumentable(FiscalDocument $document): void
    {
        $documentable = $document->documentable_type::query()->withoutGlobalScopes()->find($document->documentable_id);

        if (! $documentable instanceof Sale && ! $documentable instanceof Order) {
            return;
        }

        if ($document->status === FiscalDocument::STATUS_AUTHORIZED) {
            $documentable->forceFill([
                'fiscal_document_number' => $document->number,
                'fiscal_document_key' => $document->access_key,
                // OS: link público (tracking_token) para o cliente; venda: rota autenticada.
                'fiscal_document_url' => $documentable instanceof Order && filled($documentable->tracking_token)
                    ? route('os.fiscal-proof.pdf', ['token' => $documentable->tracking_token])
                    : route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']),
                'fiscal_issued_at' => $document->issued_at,
                'fiscal_registered_by' => $document->requested_by,
            ])->save();
        } elseif ($document->status === FiscalDocument::STATUS_CANCELLED && $documentable->fiscal_document_key === $document->access_key) {
            $documentable->forceFill([
                'fiscal_document_number' => null,
                'fiscal_document_key' => null,
                'fiscal_document_url' => null,
                'fiscal_issued_at' => null,
                'fiscal_notes' => $this->appendNote($documentable->fiscal_notes, "Nota {$document->number} cancelada."),
            ])->save();
        }
    }

    /**
     * Cobrança de contrato: registra a mudança de situação fiscal no histórico do contrato e, na
     * primeira autorização, agenda o envio automático ao cliente (o job confere a opção do contrato).
     */
    private function trackContractReceivable(FiscalDocument $document, ?string $previousStatus): void
    {
        if ($document->documentable_type !== AccountReceivable::class || $previousStatus === $document->status) {
            return;
        }

        $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($document->documentable_id);

        if (! $receivable?->isMaintenanceContract()) {
            return;
        }

        MaintenanceContractLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $receivable->tenant_id,
            'maintenance_contract_id' => $receivable->source_id,
            'user_id' => null,
            'action' => 'invoice_'.$document->status,
            'data' => array_filter([
                'account_receivable_id' => $receivable->id,
                'fiscal_document_id' => $document->id,
                'number' => $document->number,
                'error' => $document->error_message,
            ], fn ($value) => $value !== null),
        ]);

        if ($document->status === FiscalDocument::STATUS_AUTHORIZED) {
            SendFiscalDocumentToCustomer::dispatch($document->id, FiscalDocumentDelivery::ORIGIN_AUTOMATIC)->afterCommit();
        }
    }

    private function appendNote(?string $notes, string $line): string
    {
        return trim(trim((string) $notes)."\n".$line);
    }
}
