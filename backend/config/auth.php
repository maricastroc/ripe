<?php

declare(strict_types=1);

use App\Models\Visitor;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'visitors',
        ],
    ],

    'providers' => [
        'visitors' => [
            'driver' => 'eloquent',
            'model' => Visitor::class,
        ],
    ],
];
