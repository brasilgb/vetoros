<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('web chat proxy keeps the visitor token in an http only cookie', function () {
    config([
        'services.web_chat.url' => 'https://leads.example.test',
        'services.web_chat.token' => 'service-secret',
    ]);
    Http::fake([
        'https://leads.example.test/api/web-chat/conversations' => Http::response([
            'conversation_id' => 'e5bb3a70-f718-46c8-a100-8c9d85a6f69d',
            'token' => 'visitor-secret',
            'messages' => [],
        ], 201),
    ]);

    $response = $this->postJson('/chat/conversations', [
        'message' => 'Olá',
        'client_message_id' => 'first-message',
        'page_url' => 'https://vetoros.com.br/',
    ]);

    $response->assertCreated()
        ->assertJsonMissingPath('token')
        ->assertCookie('web_chat_session');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer service-secret'));
});

test('web chat proxy reports an api outage', function () {
    config([
        'services.web_chat.url' => 'https://leads.example.test',
        'services.web_chat.token' => 'service-secret',
    ]);
    Http::fake([
        'https://leads.example.test/api/web-chat/conversations' => Http::response(['message' => 'unavailable'], 503),
    ]);

    $this->postJson('/chat/conversations', [
        'message' => 'Olá',
        'client_message_id' => 'outage-message',
    ])->assertStatus(503)->assertJsonPath('message', 'unavailable');
});

test('web chat proxy forwards the visitor identification with the session token only', function () {
    config([
        'services.web_chat.url' => 'https://leads.example.test',
        'services.web_chat.token' => 'service-secret',
    ]);
    $conversation = 'e5bb3a70-f718-46c8-a100-8c9d85a6f69d';
    Http::fake([
        "https://leads.example.test/api/web-chat/conversations/{$conversation}/contact" => Http::response(['ok' => true, 'identified' => true, 'lead_action' => 'contact_only']),
    ]);
    $cookie = Illuminate\Support\Facades\Crypt::encryptString(json_encode(['id' => $conversation, 'token' => 'visitor-secret']));

    $this->withCredentials()->withCookie('web_chat_session', $cookie)->patchJson("/chat/conversations/{$conversation}/contact", [
        'name' => 'Ana', 'whatsapp' => '(51) 99517-9173', 'subject' => 'suporte', 'whatsapp_consent' => true, 'tenant_id' => 99,
    ])->assertOk()->assertJsonPath('identified', true);

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Visitor-Token', 'visitor-secret')
        && $request['subject'] === 'suporte' && $request['whatsapp_consent'] === true && ! isset($request['tenant_id']));
});

test('web chat proxy requires name, WhatsApp and a known subject before forwarding', function () {
    config([
        'services.web_chat.url' => 'https://leads.example.test',
        'services.web_chat.token' => 'service-secret',
    ]);
    Http::fake();

    $this->patchJson('/chat/conversations/e5bb3a70-f718-46c8-a100-8c9d85a6f69d/contact', ['name' => 'Ana', 'subject' => 'vendas'])
        ->assertUnprocessable()->assertJsonValidationErrors(['whatsapp', 'subject']);
    Http::assertNothingSent();
});

test('sandbox widget proxy keeps its temporary session in a header and uses only sandbox api paths', function () {
    config(['services.web_chat.url' => 'https://leads.example.test', 'services.web_chat.token' => 'service-secret']);
    $conversation = 'e5bb3a70-f718-46c8-a100-8c9d85a6f69d';
    Http::fake([
        'https://leads.example.test/api/web-chat/sandbox/redeem' => Http::response(['session' => str_repeat('a', 64), 'conversation' => ['id' => $conversation, 'messages' => []]], 200),
        "https://leads.example.test/api/web-chat/sandbox/{$conversation}*" => Http::response(['conversation' => ['id' => $conversation, 'messages' => []]], 200),
    ]);
    $this->postJson('/chat/sandbox/access', ['code' => str_repeat('b', 64)])->assertOk();
    $this->withHeader('X-Sandbox-Session', str_repeat('a', 64))->getJson("/chat/sandbox/{$conversation}")->assertOk();
    $this->withHeader('X-Sandbox-Session', str_repeat('a', 64))->postJson("/chat/sandbox/{$conversation}/messages", ['body' => 'Olá'])->assertOk();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/api/web-chat/sandbox/{$conversation}/messages")
        && $request->hasHeader('X-Sandbox-Session', str_repeat('a', 64))
        && $request->hasHeader('Authorization', 'Bearer service-secret'));
});

test('invalid sandbox session never falls through to operational web chat', function () {
    config(['services.web_chat.url' => 'https://leads.example.test', 'services.web_chat.token' => 'service-secret']);
    Http::fake();
    $this->getJson('/chat/sandbox/e5bb3a70-f718-46c8-a100-8c9d85a6f69d')->assertUnauthorized();
    Http::assertNothingSent();
});

test('a rejected sandbox credential never triggers an operational proxy request', function (int $status) {
    config(['services.web_chat.url' => 'https://leads.example.test', 'services.web_chat.token' => 'service-secret']);
    Http::preventStrayRequests();
    $url = 'https://leads.example.test/api/web-chat/sandbox/e5bb3a70-f718-46c8-a100-8c9d85a6f69d';
    Http::fake([$url => Http::response(['message' => 'Sessão inválida.'], $status)]);
    $this->withHeader('X-Sandbox-Session', str_repeat('a', 64))->getJson('/chat/sandbox/e5bb3a70-f718-46c8-a100-8c9d85a6f69d')->assertStatus($status);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === $url);
})->with([401, 403, 404]);
