<?php

declare(strict_types=1);

use App\Providers\AgentServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\InferenceServiceProvider;
use App\Providers\RealtimeServiceProvider;

return [
    AppServiceProvider::class,
    InferenceServiceProvider::class,
    AgentServiceProvider::class,
    ApiServiceProvider::class,
    RealtimeServiceProvider::class,
];
