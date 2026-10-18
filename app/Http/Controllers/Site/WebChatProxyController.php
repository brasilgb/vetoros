<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class WebChatProxyController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page_url' => ['nullable', 'url', 'max:2048'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'client_message_id' => ['required', 'string', 'max:64'],
        ]);

        $response = $this->forward('post', '/api/web-chat/conversations', $data);
        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        $payload = $response->getData(true);
        $cookiePayload = Crypt::encryptString(json_encode([
            'id' => $payload['conversation_id'],
            'token' => $payload['token'],
        ], JSON_THROW_ON_ERROR));
        unset($payload['token']);

        return response()->json($payload, 201)->cookie(cookie(
            'web_chat_session',
            $cookiePayload,
            60 * 24 * 30,
            '/chat',
            null,
            app()->environment('production') || $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        return $this->forward('get', "/api/web-chat/conversations/{$conversation}", [], $this->visitorToken($request, $conversation));
    }

    public function send(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'client_message_id' => ['required', 'string', 'max:64'],
        ]);

        return $this->forward('post', "/api/web-chat/conversations/{$conversation}/messages", $data, $this->visitorToken($request, $conversation));
    }

    public function identify(Request $request, string $conversation): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:24'],
            'email' => ['nullable', 'email', 'max:190'],
            'company_name' => ['nullable', 'string', 'max:190'],
        ]);

        return $this->forward('patch', "/api/web-chat/conversations/{$conversation}/contact", $data, $this->visitorToken($request, $conversation));
    }

    public function close(Request $request, string $conversation): JsonResponse
    {
        return $this->forward('post', "/api/web-chat/conversations/{$conversation}/close", [], $this->visitorToken($request, $conversation));
    }

    private function visitorToken(Request $request, string $conversation): ?string
    {
        try {
            $session = json_decode(Crypt::decryptString((string) $request->cookie('web_chat_session')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return ($session['id'] ?? null) === $conversation && is_string($session['token'] ?? null)
            ? $session['token']
            : null;
    }

    private function forward(string $method, string $path, array $payload = [], ?string $visitorToken = null): JsonResponse
    {
        $base = rtrim((string) config('services.web_chat.url'), '/');
        $serviceToken = (string) config('services.web_chat.token');
        if ($base === '' || $serviceToken === '') {
            return response()->json(['message' => 'Chat indisponível no momento.'], 503);
        }

        try {
            $request = Http::acceptJson()->withToken($serviceToken)->timeout(5);
            if ($visitorToken) {
                $request = $request->withHeaders(['X-Visitor-Token' => $visitorToken]);
            }
            $response = $request->{$method}($base.$path, $payload);

            return response()->json($response->json() ?? ['message' => 'Falha na integração.'], $response->status());
        } catch (ConnectionException) {
            return response()->json(['message' => 'Chat indisponível no momento.'], 503);
        }
    }
}
