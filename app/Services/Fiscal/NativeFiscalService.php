<?php

namespace App\Services\Fiscal;

use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\Sale;
use App\Models\Tenant;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    private const STATUS_MAP = [
        'created' => FiscalDocument::STATUS_PROCESSING,
        'enqueued' => FiscalDocument::STATUS_PROCESSING,
        'received' => FiscalDocument::STATUS_PROCESSING,
        'inContingent' => FiscalDocument::STATUS_CONTINGENCY,
        'authorized' => FiscalDocument::STATUS_AUTHORIZED,
        'rejected' => FiscalDocument::STATUS_REJECTED,
        'denied' => FiscalDocument::STATUS_DENIED,
        'canceled' => FiscalDocument::STATUS_CANCELLED,
        'removed' => FiscalDocument::STATUS_FAILED,
        'disabled' => FiscalDocument::STATUS_FAILED,
    ];

    public function __construct(private readonly SpedyPayloadBuilder $payloads) {}

    public function blocker(int $tenantId, string $model): ?string
    {
        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $tenantAllowed = (bool) Tenant::query()->whereKey($tenantId)->value('automatic_fiscal_emission_enabled');

        return $setting
            ? $setting->nativeEmissionBlocker($model, $tenantAllowed)
            : (new FiscalSetting)->nativeEmissionBlocker($model, $tenantAllowed);
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

    public function download(FiscalDocument $document, string $format): Response
    {
        $this->assertNative($document);

        if (! in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED, FiscalDocument::STATUS_CONTINGENCY], true)) {
            throw new FiscalEmissionException('Arquivo disponível somente para notas autorizadas.');
        }

        return $this->clientFor($document)->downloadInvoiceFile($document->type, $document->provider_reference, $format);
    }

    /**
     * Aplica o objeto de nota da Spedy (resposta da API ou `data` do webhook).
     * Ignora regressões: um evento antigo de "processando" não desfaz uma
     * autorização ou um cancelamento já registrados.
     */
    public function applyInvoice(FiscalDocument $document, array $invoice): FiscalDocument
    {
        $providerStatus = (string) ($invoice['status'] ?? '');
        $status = self::STATUS_MAP[$providerStatus] ?? null;

        return DB::transaction(function () use ($document, $invoice, $providerStatus, $status) {
            $document = FiscalDocument::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);

            if ($status === null || $this->isRegression($document->status, $status)) {
                return $document;
            }

            $detail = (array) ($invoice['processingDetail'] ?? []);
            $authorization = (array) ($invoice['authorization'] ?? []);
            $number = $invoice['number'] ?? null;

            $document->forceFill([
                'provider_reference' => $document->provider_reference ?: ($invoice['id'] ?? null),
                'provider_status' => $providerStatus,
                'status' => $status,
                'environment' => $invoice['environmentType'] ?? $document->environment,
                'number' => filled($number) && (string) $number !== '0' ? (string) $number : $document->number,
                'series' => isset($invoice['series']) ? (string) $invoice['series'] : ($invoice['rps']['series'] ?? $document->series),
                'access_key' => $invoice['accessKey'] ?? $document->access_key,
                'authorization_protocol' => $authorization['protocol'] ?? $document->authorization_protocol,
                'issued_at' => $status === FiscalDocument::STATUS_AUTHORIZED
                    ? $this->date($authorization['date'] ?? $invoice['issuedOn'] ?? null) ?? now()
                    : $document->issued_at,
                'cancelled_at' => $status === FiscalDocument::STATUS_CANCELLED
                    ? $this->date($invoice['cancellation']['date'] ?? null) ?? now()
                    : $document->cancelled_at,
                'error_message' => in_array($status, [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED, FiscalDocument::STATUS_FAILED], true)
                    ? $this->rejectionMessage($detail)
                    : null,
                'response_payload' => $this->summary($invoice),
            ])->save();

            $this->syncDocumentable($document);

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

        $document->forceFill(['request_payload' => $this->redactPayload($payload)])->save();

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

    private function isRegression(string $current, string $next): bool
    {
        $rank = [
            FiscalDocument::STATUS_FAILED => 0,
            FiscalDocument::STATUS_PROCESSING => 1,
            FiscalDocument::STATUS_CONTINGENCY => 2,
            FiscalDocument::STATUS_REJECTED => 3,
            FiscalDocument::STATUS_DENIED => 3,
            FiscalDocument::STATUS_AUTHORIZED => 4,
            FiscalDocument::STATUS_CANCELLED => 5,
        ];

        // Rejeitada → processando é um reenvio legítimo, não regressão.
        if ($current === FiscalDocument::STATUS_REJECTED && $next === FiscalDocument::STATUS_PROCESSING) {
            return false;
        }

        return ($rank[$next] ?? 0) < ($rank[$current] ?? 0);
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

    private function rejectionMessage(array $detail): string
    {
        $message = trim((string) ($detail['message'] ?? ''));
        $code = trim((string) ($detail['code'] ?? ''));

        return mb_substr(trim(($code !== '' ? "[{$code}] " : '').($message ?: 'Nota recusada pela autoridade fiscal.')), 0, 1000);
    }

    /** Guarda somente o necessário para suporte; nada de dados pessoais completos. */
    private function summary(array $invoice): array
    {
        return array_filter([
            'id' => $invoice['id'] ?? null,
            'status' => $invoice['status'] ?? null,
            'model' => $invoice['model'] ?? null,
            'environmentType' => $invoice['environmentType'] ?? null,
            'number' => $invoice['number'] ?? null,
            'series' => $invoice['series'] ?? null,
            'accessKey' => $invoice['accessKey'] ?? null,
            'amount' => $invoice['amount'] ?? null,
            'authorization' => $invoice['authorization'] ?? null,
            'processingDetail' => $invoice['processingDetail'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function redactPayload(array $payload): array
    {
        if (isset($payload['receiver'])) {
            $payload['receiver'] = ['name' => $payload['receiver']['name'] ?? null, 'redacted' => true];
        }

        return $payload;
    }

    private function appendNote(?string $notes, string $line): string
    {
        return trim(trim((string) $notes)."\n".$line);
    }

    private function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        // Datas da Spedy sem offset estão no horário de São Paulo.
        return Carbon::parse($value, 'America/Sao_Paulo')->setTimezone(config('app.timezone'));
    }
}
