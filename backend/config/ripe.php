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

    'examples' => [
        'directory' => env('RIPE_EXAMPLES_DIRECTORY', database_path('examples')),
    ],

    'retention' => [
        'days_after_visit' => (int) env('RIPE_RETENTION_DAYS', 30),
        'maximum_days' => (int) env('RIPE_RETENTION_MAXIMUM_DAYS', 90),
        'idle_visitor_days' => 30,
    ],

    'inference' => [
        'allow_paid' => (bool) env('RIPE_ALLOW_PAID_INFERENCE', false),
    ],

    'quota' => [
        'abandoned_after_seconds' => 600,
        'daily_recheck_minutes' => 30,
        'busy_recheck_seconds' => 5,
        'typical_call_tokens' => 4000,
    ],

    'budget' => [
        'daily_usd' => (float) env('RIPE_DAILY_BUDGET_USD', 0.30),
        'visitor_share' => (float) env('RIPE_VISITOR_BUDGET_SHARE', 0.10),
        'recheck_minutes' => 10,
        'resting_below_microusd' => 5000,
    ],

    'realtime' => [
        'enabled' => (bool) env('RIPE_REALTIME_ENABLED', env('BROADCAST_CONNECTION', 'null') === 'reverb'),
        'queue_connection' => env('RIPE_REALTIME_QUEUE_CONNECTION', 'realtime'),
        'queue' => 'realtime',
        'pause_seconds' => (int) env('RIPE_REALTIME_PAUSE_SECONDS', 15),
        'stale_output_seconds' => 5,
        'stale_change_seconds' => 60,
    ],

    'gateway' => [
        'secret' => env('RIPE_GATEWAY_SECRET'),
        'required' => (bool) env('RIPE_GATEWAY_REQUIRED', true),
    ],

    'http' => [
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('RIPE_ALLOWED_ORIGINS', (string) env('APP_URL', 'http://localhost')))))),
    ],

    'limits' => [
        'plant_per_hour_per_visitor' => (int) env('RIPE_LIMIT_PLANT_PER_VISITOR', 10),
        'plant_per_hour_per_ip' => (int) env('RIPE_LIMIT_PLANT_PER_IP', 30),
        'mutations_per_minute' => (int) env('RIPE_LIMIT_MUTATIONS', 60),
        'reads_per_minute' => (int) env('RIPE_LIMIT_READS', 120),
        'plant_per_day_per_visitor' => (int) env('RIPE_LIMIT_PLANT_PER_DAY_PER_VISITOR', 8),
        'plant_per_day_per_network' => (int) env('RIPE_LIMIT_PLANT_PER_DAY_PER_NETWORK', 30),
        'plant_per_day_global' => (int) env('RIPE_LIMIT_PLANT_PER_DAY', 400),
        'revisions_per_day_per_visitor' => (int) env('RIPE_LIMIT_REVISIONS_PER_DAY_PER_VISITOR', 20),
        'revisions_per_day_per_network' => (int) env('RIPE_LIMIT_REVISIONS_PER_DAY_PER_NETWORK', 60),
        'plants_in_bed' => (int) env('RIPE_LIMIT_PLANTS_IN_BED', 6),
    ],

    'lease' => [
        'ttl' => (float) env('RIPE_LEASE_TTL', 30),
        'renew_interval' => (float) env('RIPE_LEASE_RENEW_INTERVAL', 5),
        'safety_margin' => (float) env('RIPE_LEASE_SAFETY_MARGIN', 5),
    ],
];
