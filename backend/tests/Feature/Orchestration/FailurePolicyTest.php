<?php

declare(strict_types=1);

use App\Enums\RunDeferral;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\RateLimited;
use App\Models\AgentRun;
use App\Orchestration\Execution\FailurePolicy;
use App\Orchestration\Execution\RetryDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(fn () => Date::setTestNow(CarbonImmutable::now()));

function decideFor(InferenceException $failure, int $consumedAttempts = 0): RetryDecision
{
    $run = interpretRun(plantTask());
    $run->forceFill(['attempt_count' => $consumedAttempts])->save();

    return app(FailurePolicy::class)->decide($run, $failure, CarbonImmutable::now());
}

function delayMs(RetryDecision $decision): float
{
    return CarbonImmutable::now()->diffInMilliseconds($decision->at);
}

it('waits out a transient failure for as long as the provider asks, plus at most ten percent', function (InferenceException $failure, float $seconds, RunDeferral $reason): void {
    $decision = decideFor($failure);

    expect($decision->retry)->toBeTrue()
        ->and($decision->halt)->toBeFalse()
        ->and($decision->consumesAttempt)->toBeFalse()
        ->and($decision->reason)->toBe($reason)
        ->and(delayMs($decision))->toBeGreaterThanOrEqual($seconds * 1000)->toBeLessThanOrEqual($seconds * 1100);
})->with([
    'a rate limit with a hint' => fn () => [new RateLimited('Slow down.', 7.0), 7.0, RunDeferral::RateLimited],
    'an overload without a hint' => fn () => [new ModelUnavailable('The model is overloaded.'), 15.0, RunDeferral::DriverUnavailable],
    'a hint shorter than a second' => fn () => [new RateLimited('Slow down.', 0.2), 1.0, RunDeferral::RateLimited],
    'a hint longer than five minutes' => fn () => [new RateLimited('Slow down.', 3600.0), 300.0, RunDeferral::RateLimited],
]);

it('spreads the same wait differently across runs but always the same way for one run', function (): void {
    $runs = collect(range(1, 6))->map(static fn (): AgentRun => interpretRun(plantTask()));
    $now = CarbonImmutable::now();
    $at = static fn (AgentRun $run): string => app(FailurePolicy::class)->decide($run, new RateLimited('Slow down.', 10.0), $now)->at?->format('Uu') ?? '';

    expect($runs->map($at)->unique()->count())->toBeGreaterThan(1)
        ->and($runs->map($at)->all())->toBe($runs->map($at)->all());
});
