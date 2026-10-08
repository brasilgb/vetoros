<?php

namespace App\Services;

use App\Exceptions\WhatsAppException;
use App\Models\App\WhatsappConnection;
use App\Support\WhatsAppPhone;
use Illuminate\Support\Facades\Log;

/**
 * Fachada de alto nível para WhatsApp, ciente de tenant.
 *
 * Controllers/jobs devem falar apenas com este serviço, nunca diretamente
 * com o WahaService (que não sabe o que é um tenant) nem com a API do WAHA.
 *
 *     Controller -> WhatsAppService -> WahaService -> WAHA
 */
class WhatsAppService
{
    public function __construct(
        private readonly WahaService $waha,
        private readonly WhatsappMessageTemplateService $templates,
    ) {}

    /**
     * Mapeia o status bruto retornado pelo WAHA para o vocabulário interno do VetorOS.
     */
    private const STATUS_MAP = [
        'STOPPED' => WhatsappConnection::STATUS_DISCONNECTED,
        'STARTING' => WhatsappConnection::STATUS_STARTING,
        'SCAN_QR_CODE' => WhatsappConnection::STATUS_QR_REQUIRED,
        'WORKING' => WhatsappConnection::STATUS_CONNECTED,
        'FAILED' => WhatsappConnection::STATUS_FAILED,
    ];

    private function sessionNameForTenant(int $tenantId): string
    {
        return "vetoros1-{$tenantId}";
    }

    /**
     * Garante que exista uma linha de conexão local para o tenant, sem falar com o WAHA.
     */
    public function connectionFor(int $tenantId): WhatsappConnection
    {
        return WhatsappConnection::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['session_name' => $this->sessionNameForTenant($tenantId)]
        );
    }

    /**
     * Inicia (ou reinicia) a sessão do tenant no WAHA e persiste o estado inicial.
     */
    public function connect(int $tenantId): WhatsappConnection
    {
        $connection = $this->connectionFor($tenantId);

        $result = $this->waha->createSession($connection->session_name);

        $connection->update([
            'status' => $this->mapStatus($result['status'] ?? null),
            'last_synced_at' => now(),
        ]);

        return $connection;
    }

    /**
     * Consulta o WAHA e sincroniza o status local — o banco nunca é tratado como
     * verdade absoluta, apenas como cache do último status conhecido.
     */
    public function status(int $tenantId): WhatsappConnection
    {
        $connection = $this->connectionFor($tenantId);

        try {
            $result = $this->waha->getSessionStatus($connection->session_name);
        } catch (WhatsAppException) {
            // WAHA indisponível: mantém o último status conhecido em vez de quebrar a tela.
            return $connection;
        }

        $status = $this->mapStatus($result['status'] ?? null);
        $phone = $result['me']['id'] ?? null;
        $phone = is_string($phone) ? preg_replace('/\D.*$/', '', $phone) : null;

        $wasConnected = $connection->isConnected();

        $connection->update([
            'status' => $status,
            'phone_number' => $phone ?: $connection->phone_number,
            'connected_at' => ! $wasConnected && $status === WhatsappConnection::STATUS_CONNECTED ? now() : $connection->connected_at,
            'disconnected_at' => $status === WhatsappConnection::STATUS_DISCONNECTED ? now() : $connection->disconnected_at,
            'last_synced_at' => now(),
        ]);

        return $connection->refresh();
    }

    public function qrCode(int $tenantId): string
    {
        $connection = $this->connectionFor($tenantId);

        return $this->waha->getQrCodeImage($connection->session_name);
    }

    public function disconnect(int $tenantId): WhatsappConnection
    {
        $connection = $this->connectionFor($tenantId);

        try {
            $this->waha->logoutSession($connection->session_name);
        } catch (WhatsAppException $exception) {
            Log::warning('Falha ao encerrar sessão do WAHA, atualizando estado local mesmo assim.', [
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
            ]);
        }

        $connection->update([
            'status' => WhatsappConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ]);

        return $connection->refresh();
    }

    /**
     * Envia uma mensagem de texto já pronta (template já resolvido pelo chamador).
     *
     * @throws WhatsAppException  quando o tenant não está conectado, o telefone é inválido
     *                             ou o WAHA falha — sempre com mensagem segura para o usuário.
     */
    /**
     * @return array{chat_id: string, response: array<string, mixed>} chatId de destino e resposta do WAHA
     *                                                                (o chamador guarda só o id da mensagem).
     */
    public function sendText(int $tenantId, ?string $phone, string $message): array
    {
        $connection = $this->connectionFor($tenantId);

        if (! $connection->isConnected()) {
            throw WhatsAppException::notConnected();
        }

        if (! WhatsAppPhone::isValid($phone)) {
            throw WhatsAppException::invalidPhone();
        }

        if (trim($message) === '') {
            throw WhatsAppException::sendFailed();
        }

        $chatId = $this->waha->resolveChatId($connection->session_name, WhatsAppPhone::normalize($phone));

        if (! $chatId) {
            throw WhatsAppException::notOnWhatsapp();
        }

        return [
            'chat_id' => $chatId,
            'response' => $this->waha->sendText($connection->session_name, $chatId, $message),
        ];
    }

    public function sendDocument(int $tenantId, ?string $phone, string $url, ?string $filename = null, ?string $caption = null): void
    {
        $connection = $this->connectionFor($tenantId);

        if (! $connection->isConnected()) {
            throw WhatsAppException::notConnected();
        }

        if (! WhatsAppPhone::isValid($phone)) {
            throw WhatsAppException::invalidPhone();
        }

        $chatId = $this->waha->resolveChatId($connection->session_name, WhatsAppPhone::normalize($phone));

        if (! $chatId) {
            throw WhatsAppException::notOnWhatsapp();
        }

        $this->waha->sendFile($connection->session_name, $chatId, $url, $filename, $caption);
    }

    /**
     * Resolve um template salvo pelo tenant (ou o padrão) substituindo as variáveis.
     *
     * @param  array<string, string>  $variables
     */
    public function renderTemplate(?string $template, array $variables): string
    {
        return $this->templates->render($template, $variables);
    }

    private function mapStatus(?string $wahaStatus): string
    {
        return self::STATUS_MAP[$wahaStatus] ?? WhatsappConnection::STATUS_DISCONNECTED;
    }
}
