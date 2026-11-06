<?php

return [

    'http' => [
        'verify_ssl' => filter_var(env('HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Gateway PIX Padrão
    |--------------------------------------------------------------------------
    | Opções: asaas, pixupbr, digitopay
    */
    'default_pix_gateway' => env('DEFAULT_PIX_GATEWAY', 'asaas'),

    /*
    |--------------------------------------------------------------------------
    | Asaas Configuration
    |--------------------------------------------------------------------------
    */
    'asaas' => [
        'url' => env('ASAAS_API_URL', 'https://www.asaas.com/api/v3'),
        'key' => env('ASAAS_API_KEY'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OndaPay Configuration
    |--------------------------------------------------------------------------
    */
    'ondapay' => [
        'url' => env('ONDAPAY_URL'),
        'client_id' => env('ONDAPAY_CLIENT_ID'),
        'client_secret' => env('ONDAPAY_CLIENT_SECRET'),
        'webhook_secret' => env('ONDAPAY_WEBHOOK_SECRET'),
        'postback_url' => env('ONDAPAY_POSTBACK_URL', env('APP_URL') . '/ondapay/webhook'),
    ],

    'whatsapp' => [
        'socket_secret' => env('WHATSAPP_SOCKET_SECRET'),
        'http_token' => env('WHATSAPP_HTTP_TOKEN'),
        'internal_url' => env('WHATSAPP_INTERNAL_URL', 'http://127.0.0.1:3200'),
    ],

    'zapi' => [
        'base_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io'),
        'timeout' => (int) env('ZAPI_TIMEOUT', 30),
        'connect_timeout' => (int) env('ZAPI_CONNECT_TIMEOUT', 10),
        'client_token' => env('ZAPI_CLIENT_TOKEN'),
    ],

    'comprar_sms' => [
        'base_url' => env('COMPRARSMS_API_URL', 'https://xlwblpjbcovfxlcbeiqs.supabase.co/functions/v1'),
        'token' => env('COMPRARSMS_API_TOKEN'),
        'email' => env('COMPRARSMS_API_EMAIL'),
        'password' => env('COMPRARSMS_API_PASSWORD'),
        'webhook_secret' => env('COMPRARSMS_WEBHOOK_SECRET'),
        'timeout' => (int) env('COMPRARSMS_TIMEOUT', 45),
        'connect_timeout' => (int) env('COMPRARSMS_CONNECT_TIMEOUT', 10),
        'batch_size' => max(10, (int) env('COMPRARSMS_BATCH_SIZE', 500)),
        'batch_delay_seconds' => max(0, (int) env('COMPRARSMS_BATCH_DELAY_SECONDS', 15)),
    ],

    'infobip' => [
        'base_url' => env('INFOBIP_API_URL', 'https://api.infobip.com'),
        'api_key' => env('INFOBIP_API_KEY'),
        'sender' => env('INFOBIP_SENDER'),
        'webhook_secret' => env('INFOBIP_WEBHOOK_SECRET'),
        'timeout' => (int) env('INFOBIP_TIMEOUT', 45),
        'connect_timeout' => (int) env('INFOBIP_CONNECT_TIMEOUT', 10),
        // A Infobip documenta até 50.000 destinos por requisição. A fila
        // usa um lote menor por padrão para preservar observabilidade e
        // permitir interromper uma campanha sem uma chamada gigantesca.
        'max_destinations_per_request' => max(1, (int) env('INFOBIP_MAX_DESTINATIONS_PER_REQUEST', 50000)),
        'max_segments' => max(1, (int) env('INFOBIP_MAX_SEGMENTS', 5)),
    ],

    // O fluxo de fila depende apenas deste contrato. Para trocar de plataforma,
    // adicione um adaptador em services.sms.providers sem alterar campanhas,
    // layouts, contatos ou jobs.
    'sms' => [
        'default_provider' => env('SMS_PROVIDER', 'infobip'),
        'batch_size' => max(1, (int) env('SMS_BATCH_SIZE', env('INFOBIP_BATCH_SIZE', 500))),
        'batch_delay_seconds' => max(0, (int) env('SMS_BATCH_DELAY_SECONDS', env('COMPRARSMS_BATCH_DELAY_SECONDS', 15))),
        'status_poll_hours' => max(1, (int) env('SMS_STATUS_POLL_HOURS', 48)),
        'providers' => [
            'comprarsms' => [
                'label' => 'ComprarSMS',
                'driver' => \App\Services\ComprarSmsService::class,
            ],
            'infobip' => [
                'label' => 'Infobip SMS',
                'driver' => \App\Services\InfobipSmsService::class,
            ],
        ],
    ],

];
