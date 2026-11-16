<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agent\Simulation\SimulatedModel;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\InferenceManager;
use App\Observability\AgentLog;
use App\Orchestration\AgentSettings;
use App\Orchestration\WorkerHeartbeat;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeat::class);

        $this->app->afterResolving(InferenceManager::class, function (InferenceManager $manager): void {
            $manager->extend('fake', fn (): FakeDriver => FakeDriver::respondingWith((new SimulatedModel(Config::float('inference.drivers.fake.pause')))(...)));
            $manager->resolveModelsUsing(fn (string $driver): ?string => $driver === 'ollama' ? $this->app->make(AgentSettings::class)->ollamaModel() : null);
        });
    }

    public function boot(): void
    {
        $settings = $this->app->make(AgentSettings::class);

        Event::listen(Looping::class, function (Looping $event) use ($settings): void {
            if ($event->connectionName === $settings->queueConnection()) {
                $this->app->make(WorkerHeartbeat::class)->beat();
            }
        });

        Event::listen(JobFailed::class, function (JobFailed $event) use ($settings): void {
            if ($event->connectionName === $settings->queueConnection() && $event->job->getQueue() === $settings->queue()) {
                $this->app->make(AgentLog::class)->jobFailed($event->job->resolveName(), $event->job->attempts(), $event->exception);
            }
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        DevCommands::artisan("queue:work {$settings->queueConnection()} --queue={$settings->queue()} --timeout={$settings->workerTimeout()} --sleep=1", 'agent');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
