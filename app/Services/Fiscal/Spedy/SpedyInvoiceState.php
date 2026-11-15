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

    /** E0014: a DPS (série, número, município e CNPJ) já originou uma NFS-e; reenviar duplicaria a nota. */
    public const CODE_NUMBERING_CONFLICT = 'E0014';

    /** E0010: a série da DPS não pertence à faixa exigida para o canal de API/webservice. */
    public const CODE_SERIES_RANGE = 'E0010';

    public static function localStatus(?string $providerStatus): ?string
    {
        return self::STATUS_MAP[(string) $providerStatus] ?? null;
    }

    /** Código de erro no início da mensagem gravada, ex.: "[E0014] ...". */
    public static function errorCode(?string $message): ?string
    {
        return preg_match('/^\[(\w+)\]/', (string) $message, $matches) === 1 ? $matches[1] : null;
    }

    public static function hasNumberingConflict(?string $message): bool
    {
        return self::errorCode($message) === self::CODE_NUMBERING_CONFLICT;
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
        $message = $message !== '' ? SpedyMessageTranslator::translate($message) : '';
        $code = trim((string) ($detail['code'] ?? ''));

        if ($friendly = self::friendlyMessage($code.' '.$message)) {
            return mb_substr(trim(($code !== '' ? "[{$code}] " : '').$friendly), 0, 1000);
        }

        return mb_substr(trim(($code !== '' ? "[{$code}] " : '').($message ?: 'Nota recusada pela autoridade fiscal.')), 0, 1000);
    }

    /**
     * Mensagem amigável para erros conhecidos da Spedy. SPD005: o serviço de NFS-e não está
     * disponível para o município/ambiente configurados (comum na simulação). Não troca de ambiente.
     * E0014: a DPS já virou NFS-e; reenviar duplicaria a nota. E0010: a série não serve ao canal de API.
     */
    public static function friendlyMessage(string $text): ?string
    {
        if (str_contains($text, 'SPD005')) {
            return 'A emissão de NFS-e não está disponível para o município e o ambiente configurados. Confira o código IBGE do município e o ambiente nas configurações fiscais; nenhuma troca para produção é feita automaticamente.';
        }

        if (str_contains($text, self::CODE_NUMBERING_CONFLICT)) {
            return 'Esta DPS (série e número) já originou uma NFS-e na Receita. Não reenvie: consulte a situação na Spedy antes de qualquer nova tentativa, para não duplicar a nota.';
        }

        if (str_contains($text, self::CODE_SERIES_RANGE)) {
            return 'A série enviada não é a exigida para o canal de API/webservice. Ajuste a série de NFS-e nas configurações fiscais; nenhuma série é trocada automaticamente.';
        }

        return null;
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
            'rps' => $invoice['rps'] ?? null,
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
