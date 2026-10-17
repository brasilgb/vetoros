<?php

namespace App\Services\Payments\Efi;

use App\Models\Admin\SaasPaymentSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * API Cobranças da Efí (VETOR-SAAS-EFI-02): boleto/Bolix e cartão em um passo (`/v1/charge/one-step`),
 * consulta e cancelamento, notificações por token e assinaturas recorrentes (`/v1/plan`, `/v1/subscription`).
 * Valores em centavos, como exige a API. O cartão chega só como `payment_token` (tokenizado no navegador).
 */
class EfiChargesClient
{
    public const PRODUCTION_URL = 'https://cobrancas.api.efipay.com.br';

    public const HOMOLOGATION_URL = 'https://cobrancas-h.api.efipay.com.br';

    public function __construct(private readonly ?SaasPaymentSetting $settings = null) {}

    public function settings(): SaasPaymentSetting
    {
        return $this->settings ?? SaasPaymentSetting::current();
    }

    public function baseUrl(): string
    {
        return $this->settings()->isProduction() ? self::PRODUCTION_URL : self::HOMOLOGATION_URL;
    }

    /** Boleto com Pix (Bolix): linha digitável, PDF, link e QR Code Pix quando a conta os devolve. */
    public function createBillet(array $items, array $customer, string $expireAt, string $customId, string $notificationUrl, ?string $message = null): array
    {
        return $this->data('emitir boleto', fn (PendingRequest $http) => $http->post('/v1/charge/one-step', [
            'items' => $items,
            'metadata' => ['custom_id' => $customId, 'notification_url' => $notificationUrl],
            'payment' => ['banking_billet' => array_filter([
                'expire_at' => $expireAt,
                'customer' => $customer,
                'message' => $message,
            ])],
        ]));
    }

    public function createCardCharge(array $items, array $customer, array $billingAddress, string $paymentToken, string $customId, string $notificationUrl): array
    {
        return $this->data('cobrar cartão', fn (PendingRequest $http) => $http->post('/v1/charge/one-step', [
            'items' => $items,
            'metadata' => ['custom_id' => $customId, 'notification_url' => $notificationUrl],
            'payment' => ['credit_card' => [
                'installments' => 1,
                'payment_token' => $paymentToken,
                'billing_address' => $billingAddress,
                'customer' => $customer,
            ]],
        ]));
    }

    public function getCharge(int|string $chargeId): array
    {
        return $this->data('consultar cobrança', fn (PendingRequest $http) => $http->get("/v1/charge/{$chargeId}"));
    }

    public function cancelCharge(int|string $chargeId): array
    {
        return $this->data('cancelar cobrança', fn (PendingRequest $http) => $http->put("/v1/charge/{$chargeId}/cancel"));
    }

    /** Eventos de uma notificação. O token recebido no callback não traz dados: tudo vem desta consulta. */
    public function getNotification(string $token): array
    {
        return $this->data('consultar notificação', fn (PendingRequest $http) => $http->get('/v1/notification/'.rawurlencode($token)));
    }

    public function createPlan(string $name, int $intervalMonths): array
    {
        return $this->data('criar plano de recorrência', fn (PendingRequest $http) => $http->post('/v1/plan', [
            'name' => $name,
            'interval' => $intervalMonths,
            'repeats' => null,
        ]));
    }

    public function createSubscription(int|string $planId, array $items, array $customer, array $billingAddress, string $paymentToken, string $customId, string $notificationUrl): array
    {
        return $this->data('criar assinatura no cartão', fn (PendingRequest $http) => $http->post("/v1/plan/{$planId}/subscription/one-step", [
            'items' => $items,
            'metadata' => ['custom_id' => $customId, 'notification_url' => $notificationUrl],
            'payment' => ['credit_card' => [
                'payment_token' => $paymentToken,
                'billing_address' => $billingAddress,
                'customer' => $customer,
            ]],
        ]));
    }

    public function getSubscription(int|string $subscriptionId): array
    {
        return $this->data('consultar assinatura', fn (PendingRequest $http) => $http->get("/v1/subscription/{$subscriptionId}"));
    }

    public function cancelSubscription(int|string $subscriptionId): array
    {
        return $this->data('cancelar assinatura', fn (PendingRequest $http) => $http->put("/v1/subscription/{$subscriptionId}/cancel"));
    }

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

        if (! $settings->chargesConfigured()) {
            throw new EfiException('Efí Cobranças: credenciais não configuradas.');
        }

        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->client()
                ->withBasicAuth((string) $settings->efi_charges_client_id, (string) $settings->efi_charges_client_secret)
                ->post('/v1/authorize', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $exception) {
            throw EfiException::connection('autenticar Cobranças', $exception);
        }

        if (! $response->successful() || ! filled($response->json('access_token'))) {
            throw EfiException::fromResponse('autenticar Cobranças', $response);
        }

        $token = (string) $response->json('access_token');
        Cache::put($this->tokenCacheKey(), $token, max(60, (int) $response->json('expires_in', 600) - 60));

        return $token;
    }

    private function data(string $operation, callable $send): array
    {
        $token = $this->token();

        try {
            $response = $send($this->client()->withToken($token));
        } catch (ConnectionException $exception) {
            throw EfiException::connection($operation, $exception);
        }

        if ($response->status() === 401) {
            $this->forgetToken();
        }

        if (! $response->successful()) {
            throw EfiException::fromResponse($operation, $response);
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout(20)->connectTimeout(10);
    }

    private function tokenCacheKey(): string
    {
        $settings = $this->settings();

        return 'efi:charges:token:'.$settings->efi_environment.':'.hash('sha256', (string) $settings->efi_charges_client_id);
    }
}
