<?php

declare(strict_types=1);

use App\Models\Visitor;

return [
    'defaults' => [
        'guard' => 'visitor',
    ],

    'guards' => [
        'visitor' => [
            'driver' => 'visitor-cookie',
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
