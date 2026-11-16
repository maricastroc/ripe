<?php

declare(strict_types=1);

namespace App\Observability;

use App\Enums\AttemptOutcome;
use App\Enums\QuotaWait;
use App\Enums\RunDeferral;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AgentLog
{
    public function attemptStarted(AgentRun $run, RunAttempt $attempt, CarbonImmutable $due, CarbonImmutable $now): void
    {
        $this->afterCommit('info', 'Attempt started.', [
            'task_id' => $run->task_id,
            'run_id' => $run->id,
            'attempt_id' => $attempt->id,
            'kind' => $run->kind->value,
            'attempt' => $attempt->number,
            'driver' => $attempt->driver,
            'model' => $attempt->model,
            'waited_ms' => max(0, (int) round($due->diffInMilliseconds($now))),
        ]);
    }

    public function attemptFinished(RunAttempt $attempt): void
    {
        $run = AgentRun::query()->select(['id', 'task_id', 'kind'])->find($attempt->run_id);
        $quiet = in_array($attempt->outcome, [AttemptOutcome::Succeeded, AttemptOutcome::Cancelled], true);

        $this->afterCommit($quiet ? 'info' : 'warning', 'Attempt finished.', [
            'task_id' => $run?->task_id,
            'run_id' => $attempt->run_id,
            'attempt_id' => $attempt->id,
            'kind' => $run?->kind->value,
            'attempt' => $attempt->number,
            'outcome' => $attempt->outcome?->value,
            'detail' => $attempt->outcome_detail,
            'error_class' => $attempt->error_class,
            'driver' => $attempt->driver,
            'model' => $attempt->model,
            'duration_ms' => $attempt->duration_ms,
            'ttft_ms' => $attempt->ttft_ms,
            'prompt_tokens' => $attempt->prompt_tokens,
            'completion_tokens' => $attempt->completion_tokens,
            'usage_estimated' => $attempt->usage_estimated,
        ]);
    }

    public function runDeferred(AgentRun $run, RunDeferral $reason, CarbonImmutable $until, ?QuotaWait $wait = null): void
    {
        $this->afterCommit('info', 'Run deferred.', [
            'task_id' => $run->task_id,
            'run_id' => $run->id,
            'kind' => $run->kind->value,
            'reason' => $reason->value,
            'wait' => $wait?->value,
            'until' => $until->toIso8601ZuluString(),
        ]);
    }

    public function quotaBlocked(string $scope, CarbonImmutable $until, QuotaWait $wait, ?float $retryAfter): void
    {
        $this->afterCommit('warning', 'Model quota blocked.', [
            'scope' => $scope,
            'wait' => $wait->value,
            'retry_after_seconds' => $retryAfter,
            'until' => $until->toIso8601ZuluString(),
        ]);
    }

    public function halted(?string $cause): void
    {
        Log::warning('The agent halted.', ['cause' => $cause]);
    }

    public function resumed(): void
    {
        Log::info('The agent resumed after a halt.');
    }

    public function taskStalled(Task $task): void
    {
        $this->afterCommit('warning', 'A task stalled.', ['task_id' => $task->id]);
    }

    /** @param array<string, int|bool> $sweep */
    public function swept(array $sweep): void
    {
        $recovered = array_filter($sweep, static fn (int|bool $count, string $key): bool => $key !== 'reconciled' && $count !== 0 && $count !== false, ARRAY_FILTER_USE_BOTH);

        if ($recovered === []) {
            return;
        }

        Log::log(($sweep['expired'] ?? 0) > 0 ? 'warning' : 'info', 'The reconciler recovered work.', $sweep);
    }

    public function jobFailed(string $job, int $attempts, Throwable $exception): void
    {
        Log::error('An agent job failed for good.', [
            'job' => class_basename($job),
            'attempts' => $attempts,
            'error_class' => class_basename($exception),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function afterCommit(string $level, string $message, array $context): void
    {
        DB::afterCommit(static fn () => Log::log($level, $message, $context));
    }
}
