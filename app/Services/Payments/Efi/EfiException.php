<?php

namespace App\Services\Payments\Efi;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Falha da Efí com mensagem segura para tela e log: só o código e a descrição devolvidos pela API,
 * nunca credenciais, token, certificado ou dados de cartão. `temporary` indica falha que vale retentar.
 */
class EfiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $temporary = false)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $operation, Response $response): self
    {
        $body = $response->json();
        $detail = is_array($body)
            ? ($body['error_description'] ?? $body['mensagem'] ?? $body['message'] ?? $body['error'] ?? $body['nome'] ?? null)
            : null;

        if (is_array($detail)) {
            $detail = $detail['message'] ?? json_encode($detail, JSON_UNESCAPED_UNICODE);
        }

        $status = $response->status();
        $message = "Efí ({$operation}): HTTP {$status}".($detail ? ' - '.mb_substr((string) $detail, 0, 300) : '');

        return new self($message, $status, $status === 429 || $status >= 500);
    }

    public static function connection(string $operation, \Throwable $exception): self
    {
        return new self("Efí ({$operation}): falha de conexão (".$exception::class.').', null, true);
    }
}
