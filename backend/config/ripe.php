<?php

declare(strict_types=1);

return [
    'mode' => env('RIPE_MODE', 'local'),

    'agent' => [
        'queue_connection' => 'agent',
        'queue' => 'agent',
        'pace' => env('RIPE_PACE', 'steady'),
        'max_attempts' => 3,
        'max_interruptions' => 3,
        'run_budget' => 24,
        'backoff_seconds' => 10,
        'backoff_factor' => 3,
        'transient_retry_seconds' => 15,
        'max_transient_retry_seconds' => 300,
        'halt_retry_seconds' => 60,
        'paused_retry_seconds' => 30,
        'worker_timeout' => (int) env('RIPE_WORKER_TIMEOUT', 360),
        'redispatch_after_seconds' => 600,
    ],

    'output' => [
        'store' => env('RIPE_OUTPUT_STORE'),
        'ttl_seconds' => 600,
        'flush_interval' => (float) env('RIPE_OUTPUT_FLUSH_INTERVAL', 0.25),
        'max_characters' => 60000,
    ],

    'visitor' => [
        'cookie' => env('RIPE_VISITOR_COOKIE', '__Host-ripe_visitor'),
        'secure' => (bool) env('RIPE_VISITOR_COOKIE_SECURE', true),
        'lifetime_days' => 400,
        'touch_after_minutes' => 60,
    ],

    'http' => [
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('RIPE_ALLOWED_ORIGINS', (string) env('APP_URL', 'http://localhost')))))),
    ],

    'limits' => [
        'plant_per_hour_per_visitor' => (int) env('RIPE_LIMIT_PLANT_PER_VISITOR', 10),
        'plant_per_hour_per_ip' => (int) env('RIPE_LIMIT_PLANT_PER_IP', 30),
        'mutations_per_minute' => (int) env('RIPE_LIMIT_MUTATIONS', 60),
        'reads_per_minute' => (int) env('RIPE_LIMIT_READS', 120),
    ],

    'lease' => [
        'ttl' => (float) env('RIPE_LEASE_TTL', 30),
        'renew_interval' => (float) env('RIPE_LEASE_RENEW_INTERVAL', 5),
        'safety_margin' => (float) env('RIPE_LEASE_SAFETY_MARGIN', 5),
    ],
];
