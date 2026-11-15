<?php

declare(strict_types=1);

namespace App\Providers;

use App\Realtime\Publisher;
use App\Realtime\Relay;
use App\Realtime\TaskChanges;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Relay::class);
        $this->app->singleton(TaskChanges::class);
        $this->app->singleton(Publisher::class);
    }

    public function boot(): void
    {
        Event::listen(TransactionCommitted::class, function (): void {
            $this->app->make(TaskChanges::class)->flush();
        });

        if (! $this->app->runningInConsole() || ! Config::boolean('ripe.realtime.enabled')) {
            return;
        }

        $connection = Config::string('ripe.realtime.queue_connection');
        $queue = Config::string('ripe.realtime.queue');

        DevCommands::artisan('reverb:start', 'reverb');
        DevCommands::artisan("queue:work {$connection} --queue={$queue} --sleep=0.25 --timeout=30", 'realtime');
    }
}
