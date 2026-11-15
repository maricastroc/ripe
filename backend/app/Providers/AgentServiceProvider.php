<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agent\Simulation\SimulatedModel;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\InferenceManager;
use App\Orchestration\AgentSettings;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(InferenceManager::class, function (InferenceManager $manager): void {
            $manager->extend('fake', fn (): FakeDriver => FakeDriver::respondingWith((new SimulatedModel(Config::float('inference.drivers.fake.pause')))(...)));
            $manager->resolveModelsUsing(fn (string $driver): ?string => $driver === 'ollama' ? $this->app->make(AgentSettings::class)->ollamaModel() : null);
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $settings = $this->app->make(AgentSettings::class);

        DevCommands::artisan("queue:work {$settings->queueConnection()} --queue={$settings->queue()} --timeout={$settings->workerTimeout()} --sleep=1", 'agent');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
