<?php

declare(strict_types=1);

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET') ?: hash_hmac('sha256', 'reverb', (string) env('APP_KEY')),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                'timeout' => (float) env('REVERB_TIMEOUT', 1.0),
            ],
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_CONNECT_TIMEOUT', 0.5),
                'timeout' => (float) env('REVERB_TIMEOUT', 1.0),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
