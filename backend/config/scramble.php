<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    'api_path' => 'api',

    'api_domain' => null,

    'export_path' => '../docs/api/openapi.json',

    'info' => [
        'version' => '1.0.0',
        'description' => 'The API of Ripe, a greenhouse where tasks ripen while you are away. Visitors are identified by an HTTP-only cookie issued with their first seed; changes must come from the same origin.',
    ],

    'ui' => [
        'title' => 'Ripe API',
    ],

    'servers' => [
        'Ripe' => rtrim((string) env('RIPE_PUBLIC_URL', 'https://ripe.marianacastro.dev'), '/').'/api',
    ],

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],
];
