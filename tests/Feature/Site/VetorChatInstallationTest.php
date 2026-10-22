<?php

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
});

test('public VetorOS pages install the shared widget for the VetorOS product', function () {
    config([
        'services.vetorchat.widget_key' => str_repeat('a', 48),
        'services.vetorchat.script_url' => 'https://vetorleads.example/widget.js',
    ]);

    $this->get('/privacidade')->assertOk()
        ->assertSee('data-widget-key="'.str_repeat('a', 48).'"', false)
        ->assertSee('data-product="vetoros"', false);
});

test('VetorOS keeps the visitor widget off administrative pages', function () {
    config([
        'services.vetorchat.widget_key' => str_repeat('a', 48),
        'services.vetorchat.script_url' => 'https://vetorleads.example/widget.js',
    ]);

    $this->get('/login')->assertOk()->assertDontSee('data-widget-key=', false);
});
