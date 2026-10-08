<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Services\OrderMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe eventos do WAHA. Hoje só message.ack é processado: atualiza entrega/leitura
 * das mensagens que o próprio VetorOS enviou (order_messages), dentro do tenant dono da
 * sessão. Autenticação por HMAC sha512 do corpo (X-Webhook-Hmac); sem segredo
 * configurado, recusa tudo. Nada do payload é gravado além do novo status.
 */
class WahaWebhookController extends Controller
{
    public function __invoke(Request $request, OrderMessageService $messages): JsonResponse
    {
        $secret = (string) config('services.waha.webhook_secret');
        $signature = (string) $request->header('X-Webhook-Hmac');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        if ($request->input('event') !== 'message.ack') {
            return response()->json(['ignored' => true]);
        }

        $session = (string) $request->input('session');
        $payload = (array) $request->input('payload', []);
        $id = $payload['id'] ?? null;
        $id = is_array($id) ? ($id['_serialized'] ?? null) : $id;

        if ($session === '' || ! is_string($id) || ! isset($payload['ack'])) {
            return response()->json(['ignored' => true]);
        }

        $message = $messages->applyWahaAck($session, $id, (int) $payload['ack']);

        return response()->json(['updated' => (bool) $message]);
    }
}
