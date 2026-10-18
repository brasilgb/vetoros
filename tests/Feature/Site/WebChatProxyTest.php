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
