<?php

namespace App\Services;

use App\Exceptions\WhatsAppException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter fino sobre a REST API do WAHA (https://waha.devlike.pro/).
 *
 * Não conhece tenant, conexão local ou templates — apenas fala HTTP com o
 * WAHA usando o nome de sessão que o chamador (WhatsAppService) fornecer.
 * Nunca deve ser chamado diretamente por controllers/jobs.
 */
class WahaService
{
    private function client(): PendingRequest
    {
        $baseUrl = config('services.waha.base_url');

        if (! $baseUrl) {
            throw WhatsAppException::unavailable();
        }

        $client = Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout(15)
            ->acceptJson();

        $apiKey = config('services.waha.api_key');

        return $apiKey ? $client->withHeaders(['X-Api-Key' => $apiKey]) : $client;
    }

    /**
     * Cria (ou reinicia) uma sessão WAHA para o identificador informado.
     */
    public function createSession(string $sessionName): array
    {
        $response = $this->client()->post('/api/sessions', [
            'name' => $sessionName,
            'start' => true,
            'config' => [
                'webhooks' => array_values(array_filter([$this->webhookConfig()])),
            ],
        ]);

        // O usuário já tinha conectado (ou tentado conectar) antes, então a sessão
        // já existe no WAHA. Criar de novo sempre falha com 422 "already exists" —
        // nesse caso reiniciamos a sessão existente em vez de propagar o erro.
        if ($response->status() === 422 && str_contains((string) $response->json('message'), 'already exists')) {
            return $this->restartSession($sessionName);
        }

        if (! $response->successful()) {
            Log::warning('WAHA retornou erro.', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw WhatsAppException::unavailable();
        }

        return $response->json() ?? [];
    }

    public function restartSession(string $sessionName): array
    {
        return $this->request(fn (PendingRequest $client) => $client->post("/api/sessions/{$sessionName}/restart"));
    }

    public function getSessionStatus(string $sessionName): array
    {
        return $this->request(fn (PendingRequest $client) => $client->get("/api/sessions/{$sessionName}"));
    }

    /**
     * Retorna o QR Code atual da sessão como imagem PNG (bytes brutos) para
     * ser embutido diretamente numa tag <img> (data URI) no frontend.
     */
    public function getQrCodeImage(string $sessionName): string
    {
        $baseUrl = config('services.waha.base_url');

        if (! $baseUrl) {
            throw WhatsAppException::unavailable();
        }

        try {
            $response = $this->client()->get("/api/{$sessionName}/auth/qr", ['format' => 'image']);
        } catch (\Throwable $exception) {
            Log::warning('Falha ao buscar QR Code do WAHA.', ['session' => $sessionName, 'error' => $exception->getMessage()]);

            throw WhatsAppException::unavailable();
        }

        if (! $response->successful()) {
            Log::warning('WAHA retornou erro ao buscar QR Code.', [
                'session' => $sessionName,
                'status' => $response->status(),
            ]);

            throw WhatsAppException::unavailable();
        }

        // Como o cliente envia "Accept: application/json", o WAHA responde
        // com {"mimetype":"image/png","data":"<base64>"} em vez dos bytes
        // crus do PNG, mesmo com format=image na query. Sem isso, os bytes
        // "brutos" retornados são na verdade o JSON, e a imagem fica em
        // branco no navegador.
        if (str_contains((string) $response->header('Content-Type'), 'application/json')) {
            $data = $response->json('data');

            if (! $data) {
                Log::warning('WAHA retornou JSON sem campo "data" ao buscar QR Code.', ['session' => $sessionName]);

                throw WhatsAppException::unavailable();
            }

            return base64_decode($data);
        }

        return $response->body();
    }

    public function stopSession(string $sessionName): void
    {
        $this->request(fn (PendingRequest $client) => $client->post("/api/sessions/{$sessionName}/stop"));
    }

    public function logoutSession(string $sessionName): void
    {
        $this->request(fn (PendingRequest $client) => $client->post("/api/sessions/{$sessionName}/logout"));
    }

    /**
     * Resolve o chatId real do WhatsApp para um telefone.
     *
     * Não dá para simplesmente montar "{telefone}@c.us": números de celular
     * brasileiros têm o nono dígito (ex.: 51 9 8931325), mas contas do WhatsApp
     * registradas antes da mudança continuam existindo internamente sem esse
     * dígito extra (51 8931325). Mandar para o JID "errado" não dá erro nenhum
     * — o WAHA aceita e retorna sucesso — a mensagem simplesmente nunca chega.
     * Por isso é preciso perguntar ao WhatsApp qual é o chatId de verdade antes
     * de enviar, em vez de confiar no número como está salvo no cadastro.
     *
     * @param  string  $phone  Telefone normalizado, apenas dígitos, com DDI (ex.: 5551998931325).
     * @return string|null  chatId real (ex.: "555198931325@c.us") ou null se o número não tem WhatsApp.
     */
    public function resolveChatId(string $sessionName, string $phone): ?string
    {
        $response = $this->client()->get('/api/contacts/check-exists', [
            'session' => $sessionName,
            'phone' => $phone,
        ]);

        if (! $response->successful()) {
            Log::warning('WAHA retornou erro ao checar existência do número.', [
                'session' => $sessionName,
                'status' => $response->status(),
            ]);

            throw WhatsAppException::unavailable();
        }

        if (! $response->json('numberExists')) {
            return null;
        }

        return $response->json('chatId');
    }

    /**
     * @param  string  $chatId  chatId já resolvido via resolveChatId() (ex.: "555198931325@c.us").
     */
    public function sendText(string $sessionName, string $chatId, string $text): array
    {
        return $this->request(fn (PendingRequest $client) => $client->post('/api/sendText', [
            'session' => $sessionName,
            'chatId' => $chatId,
            'text' => $text,
        ]));
    }

    public function sendFile(string $sessionName, string $chatId, string $url, ?string $filename = null, ?string $caption = null): array
    {
        return $this->request(fn (PendingRequest $client) => $client->post('/api/sendFile', [
            'session' => $sessionName,
            'chatId' => $chatId,
            'file' => array_filter([
                'url' => $url,
                'filename' => $filename,
            ]),
            'caption' => $caption,
        ]));
    }

    private function webhookConfig(): ?array
    {
        $url = config('services.waha.webhook_url');

        if (! $url) {
            return null;
        }

        $secret = config('services.waha.webhook_secret');

        // message.ack atualiza a entrega/leitura em order_messages. O HMAC (sha512 do corpo)
        // autentica o webhook: sem segredo configurado o endpoint recusa as chamadas.
        return array_filter([
            'url' => $url,
            'events' => ['session.status', 'message', 'message.ack'],
            'hmac' => filled($secret) ? ['key' => $secret] : null,
        ]);
    }

    /**
     * @param  \Closure(PendingRequest): \Illuminate\Http\Client\Response  $callback
     */
    private function request(\Closure $callback): array
    {
        try {
            $response = $callback($this->client());
        } catch (\Throwable $exception) {
            Log::warning('Falha de comunicação com o WAHA.', ['error' => $exception->getMessage()]);

            throw WhatsAppException::unavailable();
        }

        if (! $response->successful()) {
            Log::warning('WAHA retornou erro.', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw WhatsAppException::unavailable();
        }

        return $response->json() ?? [];
    }
}
