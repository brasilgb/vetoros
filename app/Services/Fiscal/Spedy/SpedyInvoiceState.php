<?php

namespace App\Services\Fiscal\Spedy;

use App\Models\App\FiscalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Regras comuns de aplicação de uma nota da Spedy sobre um documento local,
 * usadas tanto pelas notas dos clientes (FiscalDocument) quanto pelas notas
 * do SaaS (AdminFiscalDocument), que têm os mesmos campos de ciclo de vida.
 */
class SpedyInvoiceState
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

    private const RANK = [
        FiscalDocument::STATUS_FAILED => 0,
        FiscalDocument::STATUS_PROCESSING => 1,
        FiscalDocument::STATUS_CONTINGENCY => 2,
        FiscalDocument::STATUS_REJECTED => 3,
        FiscalDocument::STATUS_DENIED => 3,
        FiscalDocument::STATUS_AUTHORIZED => 4,
        FiscalDocument::STATUS_CANCELLED => 5,
    ];

    public static function localStatus(?string $providerStatus): ?string
    {
        return self::STATUS_MAP[(string) $providerStatus] ?? null;
    }

    /** Evento antigo fora de ordem não desfaz autorização/cancelamento; rejeitada → processando é reenvio. */
    public static function isRegression(string $current, string $next): bool
    {
        if ($current === FiscalDocument::STATUS_REJECTED && $next === FiscalDocument::STATUS_PROCESSING) {
            return false;
        }

        return (self::RANK[$next] ?? 0) < (self::RANK[$current] ?? 0);
    }

    /** Atributos a gravar no documento local para o objeto de nota recebido. */
    public static function attributes(Model $document, array $invoice, string $status): array
    {
        $detail = (array) ($invoice['processingDetail'] ?? []);
        $authorization = (array) ($invoice['authorization'] ?? []);
        $number = $invoice['number'] ?? null;

        return [
            'provider_reference' => $document->provider_reference ?: ($invoice['id'] ?? null),
            'provider_status' => (string) ($invoice['status'] ?? ''),
            'status' => $status,
            'environment' => $invoice['environmentType'] ?? $document->environment,
            'number' => filled($number) && (string) $number !== '0' ? (string) $number : $document->number,
            'series' => isset($invoice['series']) ? (string) $invoice['series'] : ($invoice['rps']['series'] ?? $document->series),
            'access_key' => $invoice['accessKey'] ?? $document->access_key,
            'authorization_protocol' => $authorization['protocol'] ?? $document->authorization_protocol,
            'issued_at' => $status === FiscalDocument::STATUS_AUTHORIZED
                ? self::date($authorization['date'] ?? $invoice['issuedOn'] ?? null) ?? now()
                : $document->issued_at,
            'cancelled_at' => $status === FiscalDocument::STATUS_CANCELLED
                ? self::date($invoice['cancellation']['date'] ?? null) ?? now()
                : $document->cancelled_at,
            'error_message' => in_array($status, [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED, FiscalDocument::STATUS_FAILED], true)
                ? self::rejectionMessage($detail)
                : null,
            'response_payload' => self::summary($invoice),
        ];
    }

    public static function rejectionMessage(array $detail): string
    {
        $message = trim((string) ($detail['message'] ?? ''));
        $code = trim((string) ($detail['code'] ?? ''));

        return mb_substr(trim(($code !== '' ? "[{$code}] " : '').($message ?: 'Nota recusada pela autoridade fiscal.')), 0, 1000);
    }

    /** Guarda somente o necessário para suporte; nada de dados pessoais completos. */
    public static function summary(array $invoice): array
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

    public static function redactPayload(array $payload): array
    {
        if (isset($payload['receiver'])) {
            $payload['receiver'] = ['name' => $payload['receiver']['name'] ?? null, 'redacted' => true];
        }

        return $payload;
    }

    public static function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        // Datas da Spedy sem offset estão no horário de São Paulo.
        return Carbon::parse($value, 'America/Sao_Paulo')->setTimezone(config('app.timezone'));
    }
}
