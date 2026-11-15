<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Enums\Inference\ErrorScope;
use App\Enums\RunDeferral;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\RateLimited;
use App\Models\AgentRun;
use App\Orchestration\AgentSettings;
use Carbon\CarbonImmutable;

final class FailurePolicy
{
    public function __construct(private readonly AgentSettings $settings) {}

    public function decide(AgentRun $run, InferenceException $failure, CarbonImmutable $now): RetryDecision
    {
        return match ($failure->scope()) {
            ErrorScope::Transient => RetryDecision::retry(
                $now->addMilliseconds($this->transientDelayMs($run, $failure)),
                $failure instanceof RateLimited ? RunDeferral::RateLimited : RunDeferral::DriverUnavailable,
                consumesAttempt: false,
            ),
            ErrorScope::Attempt => $run->attempt_count + 1 >= $run->max_attempts
                ? RetryDecision::fail(consumesAttempt: true)
                : RetryDecision::retry($now->addSeconds($this->settings->backoffSeconds($run->attempt_count + 1)), RunDeferral::Backoff, consumesAttempt: true),
            ErrorScope::Run => RetryDecision::fail(consumesAttempt: false),
            ErrorScope::Driver => RetryDecision::halt($now->addSeconds($this->settings->haltRetrySeconds())),
        };
    }

    private function transientDelayMs(AgentRun $run, InferenceException $failure): int
    {
        $base = $failure->retryAfter() ?? (float) $this->settings->transientRetrySeconds();
        $seconds = min((float) $this->settings->maxTransientRetrySeconds(), max(1.0, $base));
        $jitter = (crc32($run->id.'#'.$run->attempts()->count()) % 1000) / 10_000;

        return (int) round($seconds * (1 + $jitter) * 1000);
    }
}
