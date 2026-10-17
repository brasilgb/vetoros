<?php

namespace App\Http\Controllers;

use App\Models\Admin\SaasPaymentSetting;
use App\Services\Payments\SaasPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notificações da Efí (VETOR-SAAS-EFI-02), com tratamento separado para a API Pix e a API Cobranças.
 * O segredo na URL identifica esta aplicação; o conteúdo nunca é confiado: só registra o evento
 * (deduplicado) e a fila confirma consultando a API com as credenciais do VetorOS.
 */
class EfiWebhookController extends Controller
{
    public function __construct(private readonly SaasPaymentService $payments) {}

    /** API Pix: a Efí chama `{webhookUrl}/pix` com `{"pix": [...]}`; o cadastro do webhook faz um POST de teste. */
    public function pix(Request $request, string $token): JsonResponse
    {
        if (! $this->authorized($token)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $items = $request->input('pix');
        if (! is_array($items)) {
            return response()->json(['status' => 'ok']);
        }

        $queued = $this->payments->receivePixNotification(array_values(array_filter($items, 'is_array')));

        return response()->json(['status' => 'ok', 'queued' => $queued]);
    }

    /** API Cobranças: POST com o campo `notification` (token a consultar em /v1/notification). */
    public function charges(Request $request, string $token): JsonResponse
    {
        if (! $this->authorized($token)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $notification = trim((string) $request->input('notification'));
        if ($notification === '' || strlen($notification) > 120) {
            return response()->json(['error' => 'notification missing'], 400);
        }

        $this->payments->receiveChargesNotification($notification);

        return response()->json(['status' => 'ok']);
    }

    private function authorized(string $token): bool
    {
        $secret = (string) SaasPaymentSetting::current()->efi_webhook_secret;

        if ($secret === '' || ! hash_equals($secret, $token)) {
            Log::warning('Notificação Efí com segredo inválido recusada.');

            return false;
        }

        return true;
    }
}
