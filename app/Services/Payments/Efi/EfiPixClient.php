<?php

namespace App\Services\Payments\Efi;

use App\Models\Admin\SaasPaymentSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * API Pix da Efí (VETOR-SAAS-EFI-02): OAuth2 client_credentials com certificado mTLS (.p12 da conta
 * convertido em PEM e guardado criptografado). Cobrança imediata (cob), QR Code, consulta, remoção,
 * devolução e webhook. O certificado só existe em disco durante a chamada, com permissão 0600.
 */
class EfiPixClient
{
    public const PRODUCTION_URL = 'https://pix.api.efipay.com.br';

    public const HOMOLOGATION_URL = 'https://pix-h.api.efipay.com.br';

    public function __construct(private readonly ?SaasPaymentSetting $settings = null) {}

    public function settings(): SaasPaymentSetting
    {
        return $this->settings ?? SaasPaymentSetting::current();
    }

    public function baseUrl(): string
    {
        return $this->settings()->isProduction() ? self::PRODUCTION_URL : self::HOMOLOGATION_URL;
    }

    /** Cobrança imediata com txid próprio (26 a 35 caracteres alfanuméricos). Devedor vazio é omitido (opcional na API). */
    public function createImmediateCharge(string $txid, string $amount, array $debtor, int $expiresInSeconds, string $description): array
    {
        return $this->request('criar cobrança Pix', fn (PendingRequest $http) => $http->put("/v2/cob/{$txid}", array_filter([
            'calendario' => ['expiracao' => $expiresInSeconds],
            'devedor' => $debtor,
            'valor' => ['original' => $amount],
            'chave' => $this->settings()->efi_pix_key,
            'solicitacaoPagador' => Str::limit($description, 140, ''),
        ], fn ($value) => $value !== [])));
    }

    public function getCharge(string $txid): array
    {
        return $this->request('consultar cobrança Pix', fn (PendingRequest $http) => $http->get("/v2/cob/{$txid}"));
    }

    /** QR Code (imagem base64) e copia e cola de uma location. */
    public function qrCode(int|string $locationId): array
    {
        return $this->request('gerar QR Code Pix', fn (PendingRequest $http) => $http->get("/v2/loc/{$locationId}/qrcode"));
    }

    /** Remove a cobrança ainda não paga (evita pagamento em dobro quando outro meio já quitou). */
    public function removeCharge(string $txid): array
    {
        return $this->request('remover cobrança Pix', fn (PendingRequest $http) => $http->patch("/v2/cob/{$txid}", [
            'status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR',
        ]));
    }

    /** Devolução de um Pix recebido (identificada pelo e2eId e por um id de devolução próprio). */
    public function refund(string $endToEndId, string $refundId, string $amount): array
    {
        return $this->request('devolver Pix', fn (PendingRequest $http) => $http->put("/v2/pix/{$endToEndId}/devolucao/{$refundId}", [
            'valor' => $amount,
        ]));
    }

    /**
     * Webhook da chave Pix. A Efí chama `{url}/pix`; sem mTLS no servidor da aplicação, o segredo na
     * URL autentica a origem e toda confirmação é conferida consultando a cobrança na API.
     */
    public function configureWebhook(string $url): array
    {
        $key = rawurlencode((string) $this->settings()->efi_pix_key);

        return $this->request('configurar webhook Pix', fn (PendingRequest $http) => $http
            ->withHeaders(['x-skip-mtls-checking' => 'true'])
            ->put("/v2/webhook/{$key}", ['webhookUrl' => $url]));
    }

    public function getWebhook(): array
    {
        $key = rawurlencode((string) $this->settings()->efi_pix_key);

        return $this->request('consultar webhook Pix', fn (PendingRequest $http) => $http->get("/v2/webhook/{$key}"));
    }

    /** Teste de conexão: só obtém o token OAuth (não cria cobrança). */
    public function testConnection(): void
    {
        $this->forgetToken();
        $this->token();
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    private function token(): string
    {
        $settings = $this->settings();

        if (! $settings->pixConfigured()) {
            throw new EfiException('Efí Pix: credenciais, certificado ou chave Pix não configurados.');
        }

        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->withCertificate(function (?string $certificate) use ($settings) {
            try {
                return $this->client($certificate)
                    ->withBasicAuth((string) $settings->efi_pix_client_id, (string) $settings->efi_pix_client_secret)
                    ->post('/oauth/token', ['grant_type' => 'client_credentials']);
            } catch (ConnectionException $exception) {
                throw EfiException::connection('autenticar Pix', $exception);
            }
        });

        if (! $response->successful() || ! filled($response->json('access_token'))) {
            throw EfiException::fromResponse('autenticar Pix', $response);
        }

        $token = (string) $response->json('access_token');
        Cache::put($this->tokenCacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    private function request(string $operation, callable $send): array
    {
        $token = $this->token();

        $response = $this->withCertificate(function (?string $certificate) use ($send, $token, $operation) {
            try {
                return $send($this->client($certificate)->withToken($token));
            } catch (ConnectionException $exception) {
                throw EfiException::connection($operation, $exception);
            }
        });

        if ($response->status() === 401) {
            $this->forgetToken();
        }

        if (! $response->successful()) {
            throw EfiException::fromResponse($operation, $response);
        }

        return $response->json() ?? [];
    }

    private function client(?string $certificate): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(10)
            ->withOptions($certificate ? ['cert' => $certificate] : []);
    }

    /** @param  callable(?string): Response  $callback */
    private function withCertificate(callable $callback): Response
    {
        $pem = (string) $this->settings()->efi_pix_certificate;
        if ($pem === '') {
            return $callback(null);
        }

        $path = tempnam(sys_get_temp_dir(), 'efi');
        try {
            chmod($path, 0600);
            file_put_contents($path, $pem);

            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    private function tokenCacheKey(): string
    {
        $settings = $this->settings();

        return 'efi:pix:token:'.$settings->efi_environment.':'.hash('sha256', (string) $settings->efi_pix_client_id);
    }
}
