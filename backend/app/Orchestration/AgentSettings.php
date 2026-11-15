<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\HostingMode;
use App\Enums\Pace;
use App\Models\AgentSetting;
use Illuminate\Support\Facades\Config;

final class AgentSettings
{
    public function hosting(): HostingMode
    {
        return HostingMode::tryFrom(Config::string('ripe.mode')) ?? HostingMode::Public;
    }

    public function isPublic(): bool
    {
        return $this->hosting() === HostingMode::Public;
    }

    public function configuredPace(): Pace
    {
        return Pace::tryFrom(Config::string('ripe.agent.pace')) ?? Pace::Steady;
    }

    public function pace(): Pace
    {
        return $this->stored()->pace ?? $this->configuredPace();
    }

    public function isPaused(): bool
    {
        return $this->stored()->paused ?? false;
    }

    public function ollamaModel(): ?string
    {
        return $this->stored()?->ollama_model;
    }

    public function pausedRetrySeconds(): int
    {
        return Config::integer('ripe.agent.paused_retry_seconds');
    }

    public function maxAttempts(): int
    {
        return Config::integer('ripe.agent.max_attempts');
    }

    public function maxInterruptions(): int
    {
        return Config::integer('ripe.agent.max_interruptions');
    }

    public function runBudget(): int
    {
        return Config::integer('ripe.agent.run_budget');
    }

    public function backoffSeconds(int $consumedAttempts): int
    {
        return Config::integer('ripe.agent.backoff_seconds') * Config::integer('ripe.agent.backoff_factor') ** max(0, $consumedAttempts - 1);
    }

    public function transientRetrySeconds(): int
    {
        return Config::integer('ripe.agent.transient_retry_seconds');
    }

    public function maxTransientRetrySeconds(): int
    {
        return Config::integer('ripe.agent.max_transient_retry_seconds');
    }

    public function haltRetrySeconds(): int
    {
        return Config::integer('ripe.agent.halt_retry_seconds');
    }

    public function redispatchAfterSeconds(): int
    {
        return Config::integer('ripe.agent.redispatch_after_seconds');
    }

    public function workerTimeout(): int
    {
        return Config::integer('ripe.agent.worker_timeout');
    }

    public function queueConnection(): string
    {
        return Config::string('ripe.agent.queue_connection');
    }

    public function queue(): string
    {
        return Config::string('ripe.agent.queue');
    }

    public function leaseTtl(): float
    {
        return Config::float('ripe.lease.ttl');
    }

    public function renewInterval(): float
    {
        return Config::float('ripe.lease.renew_interval');
    }

    public function safetyMargin(): float
    {
        return Config::float('ripe.lease.safety_margin');
    }

    private function stored(): ?AgentSetting
    {
        return $this->isPublic() ? null : AgentSetting::current();
    }
}
