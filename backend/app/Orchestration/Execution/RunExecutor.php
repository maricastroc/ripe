<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Enums\AttemptOutcome;
use App\Enums\EventActor;
use App\Enums\Inference\ErrorScope;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\RateLimited;
use App\Growth\GrowthCalculator;
use App\Inference\InferenceManager;
use App\Inference\ModelResponse;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\AgentHalt;
use App\Orchestration\AgentSettings;
use App\Orchestration\AttemptReport;
use App\Orchestration\Leases;
use App\Orchestration\LeaseSupervisor;
use App\Orchestration\RunDispatcher;
use App\Orchestration\TaskOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class RunExecutor
{
    public function __construct(
        private readonly InferenceManager $inference,
        private readonly RunRequests $requests,
        private readonly ResultCommitter $results,
        private readonly FailurePolicy $policy,
        private readonly TaskOrchestrator $orchestrator,
        private readonly RunDispatcher $dispatcher,
        private readonly GrowthCalculator $growth,
        private readonly Leases $leases,
        private readonly AgentHalt $halt,
        private readonly AgentSettings $settings,
    ) {}

    public function execute(string $runId, ?string $queueJobId = null): void
    {
        $claim = $this->claim($runId, $queueJobId);

        if ($claim === null) {
            return;
        }

        try {
            $call = $this->requests->prepare($claim->run);
            $response = $this->inference->current()->generate($call->request, $this->supervisor($claim));
            $result = $this->results->prepare($claim->run, $call, $response);
        } catch (CallAborted $abort) {
            $this->abandon($claim, $abort);

            return;
        } catch (InferenceException $failure) {
            $this->recordFailure($claim, $failure);

            return;
        } catch (Throwable $unexpected) {
            $this->recordFailure($claim, (new InvalidModelOutput("The run failed unexpectedly: {$unexpected->getMessage()}", 0, $unexpected)), $unexpected);

            return;
        }

        $this->commit($claim, $result, $response);
    }

    public function claim(string $runId, ?string $queueJobId = null): ?Claim
    {
        return DB::transaction(function () use ($runId, $queueJobId): ?Claim {
            $taskId = AgentRun::query()->whereKey($runId)->value('task_id');

            if (! is_string($taskId)) {
                return null;
            }

            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
            $run = AgentRun::query()->whereKey($runId)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            if ($task === null || $run === null || $run->status !== RunStatus::Queued) {
                return null;
            }

            if ($run->available_at !== null && $run->available_at->greaterThan($now)) {
                $this->dispatcher->dispatch($run, $now);

                return null;
            }

            if (! in_array($task->status, [TaskStatus::Ripening, TaskStatus::Waiting], true)) {
                $run->cancel($now);
                TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'queued', 'reason' => 'task_inactive'], EventActor::System, $run);

                return null;
            }

            if ($this->halt->isHalted() || $this->anotherRunIsRunning($run)) {
                $run->defer($now->addSeconds($this->halt->isHalted() ? $this->settings->haltRetrySeconds() : 5), $this->halt->isHalted() ? RunDeferral::DriverUnavailable : RunDeferral::Pace);
                $this->dispatcher->dispatch($run, $now);

                return null;
            }

            $driver = $this->inference->current();
            $token = (string) Str::uuid();
            $expiresAt = $now->addMilliseconds((int) round($this->settings->leaseTtl() * 1000));
            $run->startLease($token, $now, $expiresAt);

            $attempt = RunAttempt::query()->create([
                'run_id' => $run->id,
                'number' => $this->nextAttemptNumber($run),
                'lease_token' => $token,
                'worker' => mb_substr(gethostname().':'.getmypid(), 0, 120),
                'queue_job_id' => $queueJobId,
                'started_at' => $now,
                'driver' => $driver->name(),
                'model' => mb_substr($driver->model(), 0, 120),
            ]);

            TaskEvent::record($task, TaskEventType::RunStarted, ['run_id' => $run->id, 'attempt' => $attempt->number], EventActor::Agent, $run, $attempt);

            return new Claim($run, $attempt, $token, $expiresAt);
        });
    }

    public function commit(Claim $claim, PreparedResult $result, ModelResponse $response): void
    {
        DB::transaction(function () use ($claim, $result, $response): void {
            [$task, $run, $attempt] = $this->lock($claim);
            $now = CarbonImmutable::now();
            $report = AttemptReport::fromResponse($response);

            if ($run === null || ! $run->holdsLease($claim->token, $now)) {
                $this->reject($task, $run, $attempt, $claim, $now, $report);

                return;
            }

            $before = $this->growth->forTask($task);
            $this->results->apply($task, $run, $result, $response->model);
            $attempt->finish(AttemptOutcome::Succeeded, $now, $report);
            $run->succeed($now);

            TaskEvent::record($task, TaskEventType::RunSucceeded, ['run_id' => $run->id, 'attempt' => $attempt->number], EventActor::Agent, $run, $attempt);

            $after = $this->growth->forTask($task);

            if ($after->stage !== $before->stage) {
                TaskEvent::record($task, TaskEventType::PlantStageChanged, [
                    'from' => $before->stage->value,
                    'to' => $after->stage->value,
                    'maturity' => round($after->maturity, 4),
                ], EventActor::Agent, $run, $attempt);
            }

            $this->orchestrator->reconcile($task);
        });
    }

    public function recordFailure(Claim $claim, InferenceException $failure, ?Throwable $cause = null): void
    {
        DB::transaction(function () use ($claim, $failure, $cause): void {
            [$task, $run, $attempt] = $this->lock($claim);
            $now = CarbonImmutable::now();
            $report = AttemptReport::fromFailure($cause ?? $failure, $failure instanceof ModelTimeout ? $failure->kind->value : null);

            if ($run === null || ! $run->holdsLease($claim->token, $now)) {
                $this->reject($task, $run, $attempt, $claim, $now, $report);

                return;
            }

            $attempt->finish($this->outcomeOf($failure), $now, $report);
            $decision = $this->policy->decide($run, $failure, $now);

            if (! $decision->retry) {
                $reason = $this->stallReason($run, $failure);
                $run->fail($reason, $now, $decision->consumesAttempt);
                TaskEvent::record($task, TaskEventType::RunFailed, ['run_id' => $run->id, 'scope' => $failure->scope()->value, 'reason' => $reason], EventActor::Agent, $run, $attempt);
                $task->transitionTo(TaskStatus::Stalled, EventActor::Agent, $reason);

                return;
            }

            if ($decision->halt) {
                $this->halt->halt($failure->getMessage(), $now);
            }

            $at = $decision->at ?? $now;
            $run->requeue($at, $decision->reason, $decision->consumesAttempt);

            TaskEvent::record($task, TaskEventType::RunRetryScheduled, [
                'run_id' => $run->id,
                'scope' => $failure->scope()->value,
                'error' => class_basename($failure),
                'message' => mb_substr($failure->getMessage(), 0, 300),
                'delay_seconds' => round(max(0.0, (float) $now->diffInMilliseconds($at)) / 1000, 3),
                'attempt_consumed' => $decision->consumesAttempt,
            ], EventActor::Agent, $run, $attempt);

            $this->dispatcher->dispatch($run, $now);
        });
    }

    public function abandon(Claim $claim, CallAborted $abort): void
    {
        DB::transaction(function () use ($claim, $abort): void {
            [$task, $run, $attempt] = $this->lock($claim);
            $now = CarbonImmutable::now();
            $cancelled = $abort->reason === 'cancelled' && $run !== null && $run->status === RunStatus::Running
                && $run->lease_token === $claim->token && $run->cancel_requested_at !== null;

            if (! $attempt->finish($cancelled ? AttemptOutcome::Cancelled : AttemptOutcome::LeaseLost, $now, AttemptReport::fromFailure($abort, $abort->reason))) {
                $attempt->recordLateUsage(AttemptReport::fromFailure($abort, $abort->reason));

                return;
            }

            if ($cancelled) {
                $run->cancel($now);
                TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'running'], EventActor::Agent, $run, $attempt);

                return;
            }

            TaskEvent::record($task, TaskEventType::RunInterrupted, ['run_id' => $claim->run->id, 'attempt' => $attempt->number, 'reason' => $abort->reason], EventActor::Agent, $run, $attempt);
        });
    }

    private function reject(Task $task, ?AgentRun $run, RunAttempt $attempt, Claim $claim, CarbonImmutable $now, AttemptReport $report): void
    {
        $cancelled = $run !== null && $run->status === RunStatus::Running && $run->lease_token === $claim->token && $run->cancel_requested_at !== null;

        if (! $attempt->finish($cancelled ? AttemptOutcome::Cancelled : AttemptOutcome::LeaseLost, $now, $report)) {
            $attempt->recordLateUsage($report);
        }

        if ($cancelled) {
            $run->cancel($now);
            TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'running'], EventActor::Agent, $run, $attempt);
        }

        TaskEvent::record($task, TaskEventType::RunResultRejected, [
            'run_id' => $claim->run->id,
            'attempt' => $attempt->number,
            'reason' => $cancelled ? 'cancelled' : 'lease_lost',
        ], EventActor::Agent, $run, $attempt);
    }

    /** @return array{0: Task, 1: ?AgentRun, 2: RunAttempt} */
    private function lock(Claim $claim): array
    {
        $task = Task::query()->whereKey($claim->run->task_id)->lockForUpdate()->firstOrFail();
        $run = AgentRun::query()->whereKey($claim->run->id)->lockForUpdate()->first();
        $attempt = RunAttempt::query()->whereKey($claim->attempt->id)->lockForUpdate()->firstOrFail();

        return [$task, $run, $attempt];
    }

    private function supervisor(Claim $claim): LeaseSupervisor
    {
        return new LeaseSupervisor(
            $this->leases,
            $claim->run->id,
            $claim->token,
            $claim->expiresAt,
            $this->settings->leaseTtl(),
            $this->settings->renewInterval(),
            $this->settings->safetyMargin(),
        );
    }

    private function anotherRunIsRunning(AgentRun $run): bool
    {
        return AgentRun::query()
            ->where('task_id', $run->task_id)
            ->where('status', RunStatus::Running)
            ->whereKeyNot($run->id)
            ->exists();
    }

    private function nextAttemptNumber(AgentRun $run): int
    {
        $last = RunAttempt::query()->where('run_id', $run->id)->max('number');

        return (is_int($last) ? $last : 0) + 1;
    }

    private function outcomeOf(InferenceException $failure): AttemptOutcome
    {
        return match (true) {
            $failure instanceof ModelTimeout => AttemptOutcome::TimedOut,
            $failure instanceof InvalidModelOutput => AttemptOutcome::InvalidOutput,
            $failure instanceof RateLimited => AttemptOutcome::RateLimited,
            $failure->scope() === ErrorScope::Transient, $failure->scope() === ErrorScope::Driver => AttemptOutcome::DriverUnavailable,
            default => AttemptOutcome::Failed,
        };
    }

    private function stallReason(AgentRun $run, InferenceException $failure): string
    {
        $label = ucfirst($run->kind->value);

        return $failure->scope() === ErrorScope::Run
            ? "{$label}: {$failure->getMessage()}"
            : "{$label} failed after {$run->max_attempts} attempts: {$failure->getMessage()}";
    }
}
