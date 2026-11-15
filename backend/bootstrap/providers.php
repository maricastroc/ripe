<?php

declare(strict_types=1);

use App\Providers\AgentServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\InferenceServiceProvider;

return [
    AppServiceProvider::class,
    InferenceServiceProvider::class,
    AgentServiceProvider::class,
];
