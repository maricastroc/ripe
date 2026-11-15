<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\AttemptOutcome;
use App\Enums\EventActor;
use App\Enums\RunStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Sweeper
{
    public function __construct(
        private readonly TaskOrchestrator $orchestrator,
        private readonly RunDispatcher $dispatcher,
        private readonly AgentHalt $halt,
        private readonly AgentSettings $settings,
        private readonly InferenceManager $inference,
    ) {}

    /** @return array{expired: int, redispatched: int, reconciled: int, halt_lifted: bool} */
    public function sweep(): array
    {
        $now = CarbonImmutable::now();

        return [
            'expired' => $this->expireLeases($now),
            'redispatched' => $this->redispatchStale($now),
            'reconciled' => $this->reconcileIdle(),
            'halt_lifted' => $this->liftHalt(),
        ];
    }

    public function expireLeases(CarbonImmutable $now): int
    {
        $expired = 0;

        foreach (AgentRun::query()->where('status', RunStatus::Running)->where('lease_expires_at', '<=', $now)->pluck('id') as $runId) {
            if (is_string($runId) && $this->expire($runId, $now)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expire(string $runId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($runId, $now): bool {
            $taskId = AgentRun::query()->whereKey($runId)->value('task_id');
            $task = is_string($taskId) ? Task::query()->whereKey($taskId)->lockForUpdate()->first() : null;
            $run = AgentRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($task === null || $run === null || $run->status !== RunStatus::Running || $run->lease_expires_at === null || $run->lease_expires_at->greaterThan($now)) {
                return false;
            }

            $attempt = RunAttempt::query()->where('run_id', $run->id)->whereNull('finished_at')->lockForUpdate()->first();
            $attempt?->finish(AttemptOutcome::Interrupted, $now, AttemptReport::withDetail('lease_expired'));
            $interruptions = $run->interruption_count + 1;

            TaskEvent::record($task, TaskEventType::RunInterrupted, [
                'run_id' => $run->id,
                'attempt' => $attempt?->number,
                'reason' => 'lease_expired',
                'interruptions' => $interruptions,
            ], EventActor::System, $run, $attempt);

            if ($run->cancel_requested_at !== null) {
                $run->cancel($now);
                TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'running'], EventActor::System, $run);

                return true;
            }

            if ($interruptions >= $this->settings->maxInterruptions()) {
                $reason = "The {$run->kind->value} run was interrupted {$interruptions} times.";
                $run->fail($reason, $now, interrupted: true);
                TaskEvent::record($task, TaskEventType::RunFailed, ['run_id' => $run->id, 'scope' => 'interruption', 'reason' => $reason], EventActor::System, $run);

                if (in_array($task->status, [TaskStatus::Ripening, TaskStatus::Waiting], true)) {
                    $task->transitionTo(TaskStatus::Stalled, EventActor::System, $reason);
                }

                return true;
            }

            $run->requeue($now, null, interrupted: true);
            $this->dispatcher->dispatch($run, $now);
            $this->orchestrator->reconcile($task);

            return true;
        });
    }

    public function redispatchStale(CarbonImmutable $now): int
    {
        $stale = AgentRun::query()
            ->where('status', RunStatus::Queued)
            ->where('available_at', '<=', $now->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $now->subSeconds($this->settings->redispatchAfterSeconds())))
            ->get();

        foreach ($stale as $run) {
            $this->dispatcher->dispatch($run, $now);
        }

        return $stale->count();
    }

    public function reconcileIdle(): int
    {
        $tasks = Task::query()
            ->whereIn('status', [TaskStatus::Ripening, TaskStatus::Waiting])
            ->whereDoesntHave('runs', fn ($query) => $query->whereIn('status', [RunStatus::Queued, RunStatus::Running]))
            ->pluck('id');

        foreach ($tasks as $taskId) {
            if (is_string($taskId)) {
                $this->orchestrator->reconcileTask($taskId);
            }
        }

        return $tasks->count();
    }

    public function liftHalt(): bool
    {
        if (! $this->halt->isHalted() || ! $this->inference->current()->health()->isAvailable()) {
            return false;
        }

        $this->halt->lift();

        return true;
    }
}
