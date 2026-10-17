<?php

/*
| SMTP institucional da plataforma (RootAdmin e avisos do SaaS) — VETOR-STABILIZE-03.
| Lê ABRASIL_MAIL_* (padrão do infra-abrasil) e aceita MAIL_* como alternativa; valor vazio conta
| como ausente. A senha vem só do ambiente (nunca do repositório). O SMTP de cada empresa não passa
| por aqui: fica em `others` e é montado por App\Support\TenantMailConfig::tenantMailer().
*/
$institutionalMail = (function (): array {
    $pick = function (string $name, string $fallback) {
        $value = env($name);

        return $value === null || $value === '' ? env($fallback) : $value;
    };

    $port = $pick('ABRASIL_MAIL_PORT', 'MAIL_PORT');
    $encryption = $pick('ABRASIL_MAIL_ENCRYPTION', 'MAIL_ENCRYPTION');

    return [
        'host' => $pick('ABRASIL_MAIL_HOST', 'MAIL_HOST'),
        'port' => $port,
        'encryption' => $encryption,
        // 465/SSL: TLS desde a conexão (smtps); 587: STARTTLS negociado pelo transporte smtp.
        'scheme' => env('MAIL_SCHEME') ?: ((int) $port === 465 || in_array(strtolower((string) $encryption), ['ssl', 'smtps'], true) ? 'smtps' : null),
        'username' => $pick('ABRASIL_MAIL_USERNAME', 'MAIL_USERNAME'),
        'password' => $pick('ABRASIL_MAIL_PASSWORD', 'MAIL_PASSWORD'),
        'from_address' => $pick('ABRASIL_MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS'),
    ];
})();

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'system' => [
        'mailer' => env('MAIL_MAILER', 'log'),
        'host' => $institutionalMail['host'],
        'port' => $institutionalMail['port'],
        'encryption' => $institutionalMail['encryption'],
        'scheme' => $institutionalMail['scheme'],
        'url' => env('MAIL_URL'),
        'username' => $institutionalMail['username'],
        'password' => $institutionalMail['password'],
        'from_address' => $institutionalMail['from_address'],
        'from_name' => env('MAIL_FROM_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            // Mailer padrão (recuperação de senha, verificação de e-mail): o mesmo SMTP institucional.
            'transport' => 'smtp',
            'scheme' => $institutionalMail['scheme'],
            'url' => env('MAIL_URL'),
            'host' => $institutionalMail['host'] ?: '127.0.0.1',
            'port' => $institutionalMail['port'] ?: 2525,
            'username' => $institutionalMail['username'],
            'password' => $institutionalMail['password'],
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => $institutionalMail['from_address'] ?: 'hello@example.com',
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
