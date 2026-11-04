<?php

namespace App\Services\LeadsIntegration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Envio HTTP ao VetorLeads. Só HTTPS com nome de domínio público, sem usuário na URL e sem
 * redirecionamento. O token vai no cabeçalho Authorization e nunca em logs ou mensagens.
 */
class LeadsIntegrationClient
{
    /** Motivo da recusa do endpoint, ou null quando pode ser salvo. */
    public static function urlError(?string $url): ?string
    {
        $url = trim((string) $url);
        $parts = parse_url($url);

        if ($url === '' || $parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Informe a URL completa do endpoint, começando com https://.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'O endpoint do VetorLeads precisa usar HTTPS.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Não coloque usuário ou senha na URL.';
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.') || $host === 'localhost') {
            return 'Use um nome de domínio público para o endpoint.';
        }

        return null;
    }

    /**
     * @throws ConnectionException quando a conexão falha (transitório)
     */
    public function post(array $payload, string $url, string $token): Response
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(10)
            ->post($url, $payload);
    }
}
