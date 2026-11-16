<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Enums\AttemptOutcome;
use App\Enums\EventActor;
use App\Enums\Inference\ErrorScope;
use App\Enums\QuotaWait;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\AdmissionRefused;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\RateLimited;
use App\Growth\GrowthCalculator;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Contracts\QuotaLimited;
use App\Inference\InferenceManager;
use App\Inference\ModelResponse;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Observability\AgentLog;
use App\Observability\PublicError;
use App\Orchestration\AgentHalt;
use App\Orchestration\AgentSettings;
use App\Orchestration\AttemptReport;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\Budget\Reservation;
use App\Orchestration\Budget\Shortfall;
use App\Orchestration\Leases;
use App\Orchestration\LeaseSupervisor;
use App\Orchestration\Output\PartialOutput;
use App\Orchestration\Quota\QuotaGate;
use App\Orchestration\Quota\QuotaShortfall;
use App\Orchestration\Quota\QuotaTicket;
use App\Orchestration\RunDispatcher;
use App\Orchestration\TaskOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
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
        private readonly PartialOutput $output,
        private readonly InferenceBudget $budget,
        private readonly QuotaGate $quota,
        private readonly AgentLog $log,
    ) {}

    public function execute(string $runId, ?string $queueJobId = null): void
    {
        $claim = $this->claim($runId, $queueJobId);

        if ($claim === null) {
            return;
        }

        Context::add(['task_id' => $claim->run->task_id, 'run_id' => $claim->run->id, 'attempt_id' => $claim->attempt->id]);

        try {
            $this->attempt($claim);
        } finally {
            $this->output->forget($claim->attempt->id);
            Context::forget(['task_id', 'attempt_id']);
        }
    }

    private function attempt(Claim $claim): void
    {
        try {
            $call = $claim->call ?? $this->requests->prepare($claim->run);
            $response = $this->inference->current()->generate($call->request, $this->supervisor($claim), $this->output->open($claim->run, $claim->attempt));
            $result = $this->results->prepare($claim->run, $call, $response);
        } catch (CallAborted $abort) {
            $this->abandon($claim, $abort);

            return;
        } catch (InferenceException $failure) {
            $this->recordFailure($claim, $failure);

            return;
        } catch (Throwable $unexpected) {
            report($unexpected);
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

            $deferral = match (true) {
                $this->settings->isPaused() => [RunDeferral::Paused, $this->settings->pausedRetrySeconds()],
                $this->halt->isHalted() => [RunDeferral::DriverUnavailable, $this->settings->haltRetrySeconds()],
                $this->anotherRunIsRunning($run) => [RunDeferral::Pace, 5],
                default => null,
            };

            if ($deferral !== null) {
                $run->defer($now->addSeconds($deferral[1]), $deferral[0]);
                $this->dispatcher->dispatch($run, $now);

                return null;
            }

            $driver = $this->inference->current();

            if ($driver->isMetered() && $this->settings->isPublic() && ! Config::boolean('ripe.inference.allow_paid')) {
                $this->holdForDriver($run, 'The public greenhouse only runs on a free model.', $now, 'paid_driver_refused');

                return null;
            }

            $call = $this->prepare($run);

            try {
                [$ticket, $reservation] = $call === null ? [null, null] : $this->admit($driver, $task, $call, $now);
            } catch (DriverMisconfigured $misconfigured) {
                $this->holdForDriver($run, $misconfigured->getMessage(), $now, class_basename($misconfigured));

                return null;
            } catch (AdmissionRefused $refused) {
                $this->rest($task, $run, $refused->refusal, $now);

                return null;
            }

            $due = $run->available_at ?? $run->created_at ?? $now;
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
                'reserved_microusd' => $reservation->amount ?? 0,
                'budget_day' => $reservation?->day,
                'budget_visitor_id' => $reservation?->visitorId,
            ]);

            if ($ticket !== null) {
                $this->quota->attach($ticket, $attempt);
            }

            TaskEvent::record($task, TaskEventType::RunStarted, ['run_id' => $run->id, 'attempt' => $attempt->number], EventActor::Agent, $run, $attempt);
            $this->log->attemptStarted($run, $attempt, $due, $now);

            return new Claim($run, $attempt, $token, $expiresAt, $call);
        });
    }

    public function commit(Claim $claim, PreparedResult $result, ModelResponse $response): void
    {
        DB::transaction(function () use ($claim, $result, $response): void {
            $locked = $this->lock($claim);

            if ($locked === null) {
                return;
            }

            [$task, $run, $attempt] = $locked;
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
            $locked = $this->lock($claim);

            if ($locked === null) {
                return;
            }

            [$task, $run, $attempt] = $locked;
            $now = CarbonImmutable::now();
            $report = AttemptReport::fromFailure($cause ?? $failure, $failure instanceof ModelTimeout ? $failure->kind->value : null);

            if ($run === null || ! $run->holdsLease($claim->token, $now)) {
                $this->reject($task, $run, $attempt, $claim, $now, $report);

                return;
            }

            $attempt->finish($this->outcomeOf($failure), $now, $report);
            $decision = $this->policy->decide($run, $failure, $now);

            if ($failure instanceof RateLimited) {
                $this->blockQuota($failure, $now);
            }

            if (! $decision->retry) {
                $reason = $this->stallReason($run, $failure, $cause);
                $run->fail($reason, $now, $decision->consumesAttempt);
                TaskEvent::record($task, TaskEventType::RunFailed, ['run_id' => $run->id, 'scope' => $failure->scope()->value, 'reason' => $reason], EventActor::Agent, $run, $attempt);
                $task->transitionTo(TaskStatus::Stalled, EventActor::Agent, $reason);

                return;
            }

            if ($decision->halt) {
                $this->halt->halt($failure->getMessage(), $now, class_basename($failure));
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
            $locked = $this->lock($claim);

            if ($locked === null) {
                return;
            }

            [$task, $run, $attempt] = $locked;
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

    /** @return array{0: Task, 1: ?AgentRun, 2: RunAttempt}|null */
    private function lock(Claim $claim): ?array
    {
        $task = Task::query()->whereKey($claim->run->task_id)->lockForUpdate()->first();
        $run = AgentRun::query()->whereKey($claim->run->id)->lockForUpdate()->first();
        $attempt = RunAttempt::query()->whereKey($claim->attempt->id)->lockForUpdate()->first();

        return $task === null || $attempt === null ? null : [$task, $run, $attempt];
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

    private function prepare(AgentRun $run): ?PreparedCall
    {
        try {
            return $this->requests->prepare($run);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{?QuotaTicket, ?Reservation} */
    private function admit(ModelDriver $driver, Task $task, PreparedCall $call, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($driver, $task, $call, $now): array {
            $ticket = null;

            if ($driver instanceof QuotaLimited) {
                $admitted = $this->quota->admit($driver->quotaScope(), $driver->quotaLimits(), $driver->tokenDemand($call->request), $now);
                $ticket = $admitted instanceof QuotaShortfall ? throw new AdmissionRefused($admitted) : $admitted;
            }

            $ceiling = $driver->maximumCost($call->request);
            $reservation = null;

            if ($ceiling !== null && $ceiling > 0) {
                $reserved = $this->budget->reserve($task, $ceiling, $now);
                $reservation = $reserved instanceof Shortfall ? throw new AdmissionRefused($reserved) : $reserved;
            }

            return [$ticket, $reservation];
        });
    }

    private function rest(Task $task, AgentRun $run, QuotaShortfall|Shortfall $refusal, CarbonImmutable $now): void
    {
        if ($refusal instanceof Shortfall && $run->deferred_reason !== RunDeferral::Budget) {
            TaskEvent::record($task, TaskEventType::BudgetDeferred, ['run_id' => $run->id, 'scope' => $refusal->scope->value], EventActor::System, $run);
        }

        if ($refusal instanceof QuotaShortfall && $refusal->wait === QuotaWait::Daily && $run->deferred_reason !== RunDeferral::Quota) {
            TaskEvent::record($task, TaskEventType::QuotaDeferred, ['run_id' => $run->id, 'wait' => $refusal->wait->value], EventActor::System, $run);
        }

        $reason = $refusal instanceof Shortfall ? RunDeferral::Budget : RunDeferral::Quota;

        if ($run->deferred_reason !== $reason) {
            $this->log->runDeferred($run, $reason, $refusal->retryAt, $refusal instanceof QuotaShortfall ? $refusal->wait : null);
        }

        $run->defer($refusal->retryAt, $reason);
        $this->dispatcher->dispatch($run, $now);
    }

    private function holdForDriver(AgentRun $run, string $reason, CarbonImmutable $now, string $cause): void
    {
        $this->halt->halt($reason, $now, $cause);
        $run->defer($now->addSeconds($this->settings->haltRetrySeconds()), RunDeferral::DriverUnavailable);
        $this->dispatcher->dispatch($run, $now);
    }

    private function blockQuota(RateLimited $limited, CarbonImmutable $now): void
    {
        $driver = $this->inference->current();

        if (! $driver instanceof QuotaLimited) {
            return;
        }

        $seconds = $limited->retryAfter() ?? ($limited->daily ? 60.0 * Config::integer('ripe.quota.daily_recheck_minutes') : (float) Config::integer('ripe.quota.busy_recheck_seconds'));

        $until = $now->addMilliseconds((int) round($seconds * 1000));
        $wait = $limited->daily ? QuotaWait::Daily : QuotaWait::Capacity;

        try {
            $this->quota->block($driver->quotaScope(), $until, $wait, $now);
            $this->log->quotaBlocked($driver->quotaScope(), $until, $wait, $limited->retryAfter());
        } catch (DriverMisconfigured) {
            return;
        }
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

    private function stallReason(AgentRun $run, InferenceException $failure, ?Throwable $cause): string
    {
        $label = ucfirst($run->kind->value);
        $public = PublicError::of($this->outcomeOf($failure), class_basename($cause ?? $failure)) ?? 'it failed.';

        return $failure->scope() === ErrorScope::Run
            ? "{$label}: {$public}"
            : "{$label} failed after {$run->max_attempts} attempts. {$public}";
    }
}
