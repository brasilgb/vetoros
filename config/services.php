<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'mercadopago' => [
        'token' => env('MP_ACCESS_TOKEN'),
        'webhook_token' => env('MP_WEBHOOK_TOKEN'),
    ],

    'crm_abrasil' => [
        'registration_check_token' => env('CRM_REGISTRATION_CHECK_TOKEN'),
    ],

    'waha' => [
        'base_url' => env('WAHA_BASE_URL'),
        'api_key' => env('WAHA_API_KEY'),
        'webhook_url' => env('WAHA_WEBHOOK_URL'),
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
    ],

    // Emissão fiscal nativa. A conta Spedy é da plataforma: a chave da empresa
    // titular cadastra cada tenant como empresa emissora e nunca é exibida a ele.
    'spedy' => [
        'environment' => env('SPEDY_ENVIRONMENT', 'sandbox'),
        'base_urls' => [
            'sandbox' => 'https://sandbox-api.spedy.com.br/v1',
            'production' => 'https://api.spedy.com.br/v1',
        ],
        'owner_api_key' => env('SPEDY_OWNER_API_KEY'),
        'webhook_secret' => env('SPEDY_WEBHOOK_SECRET'),
        'timeout' => (int) env('SPEDY_TIMEOUT', 30),
        'technical_responsible' => [
            'federal_tax_number' => env('SPEDY_TECH_RESPONSIBLE_CNPJ'),
            'contact_name' => env('SPEDY_TECH_RESPONSIBLE_NAME'),
            'email' => env('SPEDY_TECH_RESPONSIBLE_EMAIL'),
            'phone' => env('SPEDY_TECH_RESPONSIBLE_PHONE'),
        ],
    ],
];
