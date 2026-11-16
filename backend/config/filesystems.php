<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
            'report' => false,
        ],

        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_REGION', 'auto'),
            'bucket' => env('BACKUP_BUCKET'),
            'endpoint' => env('BACKUP_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],
    ],

    'links' => [],
];
