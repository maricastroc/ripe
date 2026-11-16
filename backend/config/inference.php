<?php

declare(strict_types=1);

return [
    'driver' => env('RIPE_INFERENCE_DRIVER', 'ollama'),

    'output_tokens' => [
        'interpret' => 900,
        'plan' => 1100,
        'generate' => 2200,
        'review' => 1200,
        'revise' => 2400,
    ],

    'temperature' => [
        'interpret' => 0.3,
        'plan' => 0.4,
        'generate' => 0.6,
        'review' => 0.3,
        'revise' => 0.5,
    ],

    'drivers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'project' => env('OPENAI_PROJECT'),
            'model' => env('OPENAI_MODEL'),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
            'service_tier' => env('OPENAI_SERVICE_TIER'),
            'reasoning_allowance' => [
                'none' => 0,
                'minimal' => 1024,
                'low' => 4096,
                'medium' => 16384,
                'high' => 32768,
                'xhigh' => 49152,
                'max' => 65536,
            ],
            'timeouts' => [
                'connect' => 10,
                'first_byte' => 60,
                'idle' => 180,
                'total' => 300,
            ],
            'models' => [
                'gpt-6-luna' => [
                    'reasoning' => true,
                    'supports_temperature' => false,
                    'prices' => [
                        'standard' => ['input' => 0.10, 'cached_input' => 0.01, 'cache_write' => 0.125, 'output' => 0.50, 'checked_on' => '2026-11-15'],
                        'flex' => ['input' => 0.05, 'cached_input' => 0.005, 'cache_write' => 0.125, 'output' => 0.25, 'checked_on' => '2026-11-15'],
                    ],
                ],
            ],
        ],

        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
            'reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low'),
            'timeouts' => [
                'connect' => 10,
                'first_byte' => 90,
                'idle' => 60,
                'total' => 240,
            ],
            'models' => [
                'openai/gpt-oss-120b' => [
                    'reasoning' => true,
                    'supports_temperature' => true,
                    'reasoning_allowance' => ['low' => 1024, 'medium' => 4096, 'high' => 8192],
                    'limits' => [
                        'requests_per_minute' => env('GROQ_REQUESTS_PER_MINUTE', 30),
                        'requests_per_day' => env('GROQ_REQUESTS_PER_DAY', 1000),
                        'tokens_per_minute' => env('GROQ_TOKENS_PER_MINUTE', 8000),
                        'tokens_per_day' => env('GROQ_TOKENS_PER_DAY', 200000),
                        'concurrency' => env('GROQ_CONCURRENCY', 1),
                    ],
                ],
            ],
        ],

        'fake' => [
            'pause' => (float) env('RIPE_FAKE_PAUSE', 0),
        ],

        'ollama' => [
            'base_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('OLLAMA_MODEL', 'gemma4:e4b'),
            'keep_alive' => '10m',
            'context_window' => 8192,
            'timeouts' => [
                'connect' => 5,
                'first_byte' => 150,
                'idle' => 60,
                'total' => 240,
            ],
        ],
    ],
];
