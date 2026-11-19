<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Gateway de pagamento fake (serviço `gateway` do docker-compose).
    'gateway' => [
        // Topologia: vem do compose, não de src/.env.
        'url' => env('GATEWAY_URL', 'http://gateway:8080'),

        // -------------------------------------------------------------------
        // FASE 2 — as alavancas de timeout e retry do NOSSO cliente HTTP.
        //
        // Cuidado com os nomes, porque são dois mundos parecidos:
        //   GATEWAY_HTTP_*         -> como o APP chama o gateway (aqui,
        //                             src/.env)
        //   GATEWAY_TIMEOUT_RATE,
        //   GATEWAY_FAIL_RATE, ... -> o CAOS que o gateway fake aplica em si
        //                             mesmo (.env da raiz, injetado pelo
        //                             compose só no container `gateway`)
        //
        // Por que em config e não hard-coded no PaymentGateway: o teste
        // observável da fase precisa mexer no timeout e no número de
        // tentativas sem editar código, e porque timeout é configuração de
        // ambiente — o que serve em dev não serve em produção.
        //
        // Os valores abaixo são um ponto de partida, não uma recomendação:
        // 3s de timeout com 3 tentativas e backoff dá um pior caso de ~10s,
        // que cabe no `fastcgi_read_timeout 60s` do nginx com folga.
        // -------------------------------------------------------------------
        'timeout' => (float) env('GATEWAY_HTTP_TIMEOUT', 3),
        'connect_timeout' => (float) env('GATEWAY_HTTP_CONNECT_TIMEOUT', 1),
        'tries' => (int) env('GATEWAY_HTTP_TRIES', 3),
        'retry_base_ms' => (int) env('GATEWAY_HTTP_RETRY_BASE_MS', 100),
    ],

];
