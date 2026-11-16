<?php

declare(strict_types=1);

$origins = (string) env('RIPE_ALLOWED_ORIGINS', (string) env('APP_URL', 'http://localhost'));

return [
    'default' => 'reverb',

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => 10_000,
            'scaling' => [
                'enabled' => false,
                'channel' => 'reverb',
                'server' => [],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],

    'apps' => [
        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET') ?: hash_hmac('sha256', 'reverb', (string) env('APP_KEY')),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                'allowed_origins' => array_values(array_filter(array_map(
                    static fn (string $origin): string => (string) parse_url(trim($origin), PHP_URL_HOST),
                    explode(',', $origins),
                ))),
                'ping_interval' => 30,
                'activity_timeout' => 30,
                'max_connections' => (int) env('REVERB_APP_MAX_CONNECTIONS', 500),
                'max_message_size' => 10_000,
                'accept_client_events_from' => 'none',
                'rate_limiting' => [
                    'enabled' => (bool) env('REVERB_APP_RATE_LIMITING_ENABLED', true),
                    'max_attempts' => 60,
                    'decay_seconds' => 60,
                    'terminate_on_limit' => false,
                ],
            ],
        ],
    ],
];
