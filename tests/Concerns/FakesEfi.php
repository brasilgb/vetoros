<?php

namespace Tests\Concerns;

use App\Models\Admin\SaasPaymentSetting;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Simulador controlado das APIs Pix e Cobranças da Efí Bank para os testes (VETOR-SAAS-EFI-02/03):
 * estado em memória, sem credencial real nem cobrança real. Certificado gerado na hora.
 */
trait FakesEfi
{
    private const PIX = 'https://pix-h.api.efipay.com.br';

    private const CHARGES = 'https://cobrancas-h.api.efipay.com.br';

    /** Estado do simulador da Efí. */
    private array $cobs = [];

    private array $efiCharges = [];

    private array $notifications = [];

    private array $subscriptions = [];

    private int $nextChargeId = 5000;

    private bool $pixDown = false;

    private bool $chargesRejectIdentifier = false;

    /** Efí configurada e homologada com os meios indicados (padrão: todos) e o simulador ligado. */
    protected function configureEfi(?array $methods = null): void
    {
        SaasPaymentSetting::current()->forceFill([
            'efi_environment' => 'homologation',
            'efi_charges_client_id' => 'Client_Id_charges',
            'efi_charges_client_secret' => 'Client_Secret_charges',
            'efi_pix_client_id' => 'Client_Id_pix',
            'efi_pix_client_secret' => 'Client_Secret_pix',
            'efi_pix_certificate' => self::throwawayPem(),
            'efi_pix_key' => 'pix@abrasil.test',
            'efi_payee_code' => 'abc123payee',
            'efi_methods' => $methods ?? array_keys(SaasPaymentSetting::METHODS),
            'efi_webhook_secret' => 'webhook-secret-123',
            'efi_homologated_at' => now(),
            'last_connection_ok' => true,
        ])->save();

        $this->fakeEfi();
    }

    /** Certificado autoassinado gerado na hora (nada de chave versionada no repositório). */
    protected static function throwawayPem(): string
    {
        static $pem = null;

        if ($pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'VetorOS Efi Teste'], $key), null, $key, 30);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem);
            $pem = $certPem.$keyPem;
        }

        return $pem;
    }

    // ---- Simulador -------------------------------------------------------------

    protected function fakeEfi(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);
            $method = $request->method();

            if (str_starts_with($url, self::PIX)) {
                if ($this->pixDown) {
                    return Http::response(['nome' => 'erro_interno'], 503);
                }
                if ($path === '/oauth/token') {
                    return Http::response(['access_token' => 'pix-token', 'expires_in' => 3600]);
                }
                if (preg_match('#^/v2/cob/(\w+)$#', $path, $m)) {
                    if ($method === 'PUT') {
                        $this->cobs[$m[1]] = ['txid' => $m[1], 'status' => 'ATIVA', 'loc' => ['id' => 77], 'pixCopiaECola' => '000201PIX'.$m[1], 'valor' => $request['valor'], 'pix' => []];

                        return Http::response($this->cobs[$m[1]], 201);
                    }
                    if ($method === 'PATCH') {
                        $this->cobs[$m[1]]['status'] = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';

                        return Http::response($this->cobs[$m[1]]);
                    }

                    return isset($this->cobs[$m[1]]) ? Http::response($this->cobs[$m[1]]) : Http::response(['nome' => 'cobranca_nao_encontrada'], 404);
                }
                if (preg_match('#^/v2/loc/\d+/qrcode$#', $path)) {
                    return Http::response(['qrcode' => '000201QR', 'imagemQrcode' => 'data:image/png;base64,iVBORw0KGgo=']);
                }
                if (preg_match('#^/v2/webhook/#', $path)) {
                    return Http::response(['webhookUrl' => $request['webhookUrl'] ?? null]);
                }
                if (preg_match('#^/v2/pix/(\w+)/devolucao/(\w+)$#', $path, $m)) {
                    foreach ($this->cobs as $txid => $cob) {
                        foreach ($cob['pix'] as $i => $pix) {
                            if ($pix['endToEndId'] === $m[1]) {
                                $this->cobs[$txid]['pix'][$i]['devolucoes'][] = ['id' => $m[2], 'valor' => $request['valor'], 'status' => 'DEVOLVIDO'];
                            }
                        }
                    }

                    return Http::response(['id' => $m[2], 'status' => 'EM_PROCESSAMENTO'], 201);
                }
            }

            if (str_starts_with($url, self::CHARGES)) {
                if ($path === '/v1/authorize') {
                    return Http::response(['access_token' => 'charges-token', 'expires_in' => 600]);
                }
                if ($path === '/v1/charge/one-step') {
                    if ($this->chargesRejectIdentifier) {
                        return Http::response(['nome' => 'identificador_invalido', 'mensagem' => 'Client_Secret_charges'], 400);
                    }

                    $id = $this->nextChargeId++;
                    $billet = isset($request['payment']['banking_billet']);
                    $this->efiCharges[$id] = [
                        'charge_id' => $id, 'total' => $request['items'][0]['value'], 'status' => $billet ? 'waiting' : ($request['payment']['credit_card']['payment_token'] === 'refused-token' ? 'unpaid' : 'paid'),
                        'custom_id' => $request['metadata']['custom_id'], 'request' => $request->data(),
                    ];
                    $data = ['charge_id' => $id, 'status' => $this->efiCharges[$id]['status'], 'total' => $request['items'][0]['value']];
                    if ($billet) {
                        $data += ['barcode' => '34191.79001 01043.510047', 'billet_link' => 'https://boleto.test/'.$id, 'pdf' => ['charge' => 'https://boleto.test/'.$id.'.pdf'],
                            'pix' => ['qrcode' => '000201BOLIX'.$id, 'qrcode_image' => 'data:image/svg+xml;base64,PHN2Zz4=']];
                    }

                    return Http::response(['code' => 200, 'data' => $data]);
                }
                if (preg_match('#^/v1/charge/(\d+)/cancel$#', $path, $m)) {
                    $this->efiCharges[$m[1]]['status'] = 'canceled';

                    return Http::response(['code' => 200]);
                }
                if (preg_match('#^/v1/charge/(\d+)$#', $path, $m)) {
                    return isset($this->efiCharges[$m[1]]) ? Http::response(['code' => 200, 'data' => $this->efiCharges[$m[1]]]) : Http::response(['error' => 'not_found'], 404);
                }
                if (preg_match('#^/v1/notification/(.+)$#', $path, $m)) {
                    return Http::response(['code' => 200, 'data' => $this->notifications[urldecode($m[1])] ?? []]);
                }
                if ($path === '/v1/plan') {
                    return Http::response(['code' => 200, 'data' => ['plan_id' => 900 + $request['interval'], 'interval' => $request['interval']]]);
                }
                if (preg_match('#^/v1/plan/(\d+)/subscription/one-step$#', $path, $m)) {
                    $subscriptionId = 3000 + count($this->subscriptions);
                    $chargeId = $this->nextChargeId++;
                    $this->subscriptions[$subscriptionId] = ['plan' => $m[1], 'value' => $request['items'][0]['value']];
                    $this->efiCharges[$chargeId] = ['charge_id' => $chargeId, 'total' => $request['items'][0]['value'], 'status' => 'paid', 'subscription_id' => $subscriptionId];

                    return Http::response(['code' => 200, 'data' => ['subscription_id' => $subscriptionId, 'status' => 'active', 'charge' => ['id' => $chargeId, 'status' => 'paid']]]);
                }
                if (preg_match('#^/v1/subscription/(\d+)/cancel$#', $path, $m)) {
                    $this->subscriptions[$m[1]]['status'] = 'canceled';

                    return Http::response(['code' => 200]);
                }
            }

            // Fora da Efí: deixa outros fakes do teste responderem (preventStrayRequests barra o resto).
            return null;
        });
    }

    protected function payCob(string $txid, string $value = '1200.00', string $e2e = 'E00000000202610151000abcdef123456'): void
    {
        $this->cobs[$txid]['status'] = 'CONCLUIDA';
        $this->cobs[$txid]['pix'][] = ['endToEndId' => $e2e, 'txid' => $txid, 'valor' => $value, 'horario' => '2026-10-15T10:05:00Z'];
    }

    protected function pixWebhook(string $txid, string $e2e = 'E00000000202610151000abcdef123456', string $token = 'webhook-secret-123')
    {
        return $this->postJson('/api/webhooks/efi/'.$token.'/pix', ['pix' => [['endToEndId' => $e2e, 'txid' => $txid, 'valor' => '1200.00', 'horario' => '2026-10-15T10:05:00Z']]]);
    }

    protected function chargesNotification(string $token, array $events)
    {
        $this->notifications[$token] = $events;

        return $this->post('/api/webhooks/efi-charges/webhook-secret-123', ['notification' => $token]);
    }

    protected function cobRequests(): int
    {
        return count(Http::recorded(fn (HttpRequest $request) => $request->method() === 'PUT' && str_contains($request->url(), '/v2/cob/')));
    }
}
