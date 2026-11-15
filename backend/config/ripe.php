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

    'lease' => [
        'ttl' => (float) env('RIPE_LEASE_TTL', 30),
        'renew_interval' => (float) env('RIPE_LEASE_RENEW_INTERVAL', 5),
        'safety_margin' => (float) env('RIPE_LEASE_SAFETY_MARGIN', 5),
    ],
];
