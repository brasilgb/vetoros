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

        // Código fixo da Efí (não é dado sensível): a orientação substitui o texto devolvido pela API.
        if ($status === 400 && is_array($body) && str_contains((string) json_encode($body, JSON_UNESCAPED_UNICODE), 'identificador_invalido')) {
            return new self("Efí ({$operation}): HTTP 400 - identificador_invalido. ".self::identifierHint($operation), $status, false);
        }

        $message = "Efí ({$operation}): HTTP {$status}".($detail ? ' - '.mb_substr((string) $detail, 0, 300) : '');

        return new self($message, $status, $status === 429 || $status >= 500);
    }

    /** Campos a conferir conforme a operação: boleto não usa o identificador de conta; cartão usa. */
    private static function identifierHint(string $operation): string
    {
        if (str_contains($operation, 'boleto')) {
            return 'Confira credenciais e ambiente da API Cobranças e os dados do boleto (valor, vencimento e pagador). O identificador de conta não é usado em boleto.';
        }

        if (str_contains($operation, 'cartão') || str_contains($operation, 'assinatura')) {
            return 'Confira o identificador de conta cadastrado (usado na tokenização do cartão), as credenciais da API Cobranças e se a API de Emissão está habilitada no ambiente.';
        }

        return 'Confira o identificador de conta, as credenciais e o ambiente configurados.';
    }

    public static function connection(string $operation, \Throwable $exception): self
    {
        return new self("Efí ({$operation}): falha de conexão (".$exception::class.').', null, true);
    }
}
