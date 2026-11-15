<?php

declare(strict_types=1);

namespace App\Providers;

use App\Inference\Contracts\ModelDriver;
use App\Inference\InferenceManager;
use App\Inference\Transport\CurlStreamTransport;
use App\Inference\Transport\StreamTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class InferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StreamTransport::class, CurlStreamTransport::class);
        $this->app->singleton(InferenceManager::class, static fn (Application $app): InferenceManager => new InferenceManager($app));
        $this->app->bind(ModelDriver::class, static fn (Application $app): ModelDriver => $app->make(InferenceManager::class)->current());
    }
}
