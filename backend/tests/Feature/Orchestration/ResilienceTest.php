<?php

declare(strict_types=1);

use App\Actions\Tasks\DeferTask;
use App\Actions\Tasks\PauseTask;
use App\Enums\AttemptOutcome;
use App\Enums\EventActor;
use App\Enums\Inference\TimeoutKind;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Enums\Stage;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\ModelRefused;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\RateLimited;
use App\Growth\GrowthCalculator;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\InferenceManager;
use App\Inference\ModelResponse;
use App\Jobs\ExecuteRun;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\AgentHalt;
use App\Orchestration\Execution\Claim;
use App\Orchestration\Execution\PreparedResult;
use App\Orchestration\Execution\ResultCommitter;
use App\Orchestration\Execution\RunExecutor;
use App\Orchestration\Execution\RunRequests;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/** @return array{0: Claim, 1: PreparedResult, 2: ModelResponse} */
function claimAndAnswer(AgentRun $run): array
{
    $claim = app(RunExecutor::class)->claim($run->id) ?? throw new RuntimeException('The run could not be claimed.');
    $call = app(RunRequests::class)->prepare($claim->run);
    $response = app(InferenceManager::class)->current()->generate($call->request);

    return [$claim, app(ResultCommitter::class)->prepare($claim->run, $call, $response), $response];
}

function processEnvironment(array $extra = []): array
{
    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        'CACHE_STORE' => 'array',
        'RIPE_PACE' => 'instant',
        ...$extra,
    ];
}

it('ignores a second delivery of the same run', function (): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    $task = plantTask();
    Bus::dispatch(new ExecuteRun(interpretRun($task)->id));

    drainAgentQueue(2);

    expect(RunAttempt::query()->where('run_id', interpretRun($task)->id)->count())->toBe(1)
        ->and(Question::query()->count())->toBe(1);
    expectJournalConsistent($task);
});

it('does nothing when a finished run is delivered again after a crash before the acknowledgement', function (): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    $task = ripen(plantTask());
    $events = TaskEvent::query()->count();

    app(RunExecutor::class)->execute(interpretRun($task)->id);

    expect(TaskEvent::query()->count())->toBe($events)
        ->and(Question::query()->count())->toBe(1);
    expectJournalConsistent($task);
});

it('rejects a late result once the lease has passed to another attempt', function (): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    $task = plantTask();
    [$late, $result, $response] = claimAndAnswer(interpretRun($task));

    travelSeconds(31);
    app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    $task = ripen($task);
    app(RunExecutor::class)->commit($late, $result, $response);

    expect($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and(Question::query()->count())->toBe(1)
        ->and(RunAttempt::query()->where('run_id', $late->run->id)->orderBy('number')->pluck('outcome')->all())->toBe([AttemptOutcome::Interrupted, AttemptOutcome::Succeeded])
        ->and(TaskEvent::query()->where('type', TaskEventType::RunResultRejected)->where('attempt_id', $late->attempt->id)->count())->toBe(1);
    expectJournalConsistent($task);
});

it('rejects a late result even when no sweep has run, because lease expiry is final', function (): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    $task = plantTask();
    [$late, $result, $response] = claimAndAnswer(interpretRun($task));

    travelSeconds(31);
    app(RunExecutor::class)->commit($late, $result, $response);

    expect($task->refresh()->interpreted_at)->toBeNull()
        ->and($late->attempt->refresh()->outcome)->toBe(AttemptOutcome::LeaseLost)
        ->and($late->attempt->prompt_tokens)->toBeGreaterThan(0);

    app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    $task = ripen($task);

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and(Question::query()->count())->toBe(1);
    expectJournalConsistent($task);
});

it('re-queues a job that arrives before its run is due instead of dropping it', function (): void {
    fakeAgent();
    $task = plantTask();
    $run = interpretRun($task);
    $run->defer(CarbonImmutable::now()->addMinutes(5), RunDeferral::Backoff);
    DB::table('jobs')->delete();

    app(RunExecutor::class)->execute($run->id);

    expect($run->refresh()->status)->toBe(RunStatus::Queued)
        ->and(DB::table('jobs')->where('queue', 'agent')->count())->toBe(1);
});

it('backs off between attempts, stalls after the last one and resumes when asked', function (): void {
    fakeAgent(first: [new ModelTimeout(TimeoutKind::Idle), new ModelTimeout(TimeoutKind::Idle), new ModelTimeout(TimeoutKind::Idle)]);
    $task = ripen(plantTask());
    $run = interpretRun($task);
    $delays = TaskEvent::query()->where('type', TaskEventType::RunRetryScheduled)->orderBy('sequence')->get()->map(static fn (TaskEvent $event): mixed => $event->payload['delay_seconds'])->all();

    expect($task->status)->toBe(TaskStatus::Stalled)
        ->and($task->stall_reason)->toContain('failed after 3 attempts')
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->attempt_count)->toBe(3)
        ->and($delays)->toBe([10, 30]);
    expectJournalConsistent($task);

    app(DeferTask::class)->handle($task);

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('waits out a rate limit without spending an attempt', function (): void {
    fakeAgent(first: [new RateLimited('Slow down.', 7.0)]);
    $task = plantTask();
    drainAgentQueue();
    $run = interpretRun($task);
    $delay = $run->updated_at?->diffInMilliseconds($run->available_at);

    expect($run->status)->toBe(RunStatus::Queued)
        ->and($run->attempt_count)->toBe(0)
        ->and($run->deferred_reason)->toBe(RunDeferral::RateLimited)
        ->and($delay)->toBeGreaterThanOrEqual(7000)->toBeLessThan(7800);

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('halts the whole agent on a driver problem instead of failing tasks', function (): void {
    fakeAgent(first: [new DriverMisconfigured('Incorrect API key provided.')]);
    $task = plantTask();
    drainAgentQueue();

    expect(app(AgentHalt::class)->reason())->toBe('Incorrect API key provided.')
        ->and($task->refresh()->status)->toBe(TaskStatus::Ripening)
        ->and(interpretRun($task)->attempt_count)->toBe(0);

    $neighbour = plantTask();
    drainAgentQueue();

    expect(RunAttempt::query()->where('run_id', interpretRun($neighbour)->id)->count())->toBe(0)
        ->and(app(Sweeper::class)->liftHalt())->toBeTrue()
        ->and(ripen($task)->status)->toBe(TaskStatus::Ripe)
        ->and(ripen($neighbour)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
    expectJournalConsistent($neighbour);
});

it('stalls on a refusal without retrying, and resumes when asked', function (): void {
    fakeAgent(first: [new ModelRefused("I can't help with that request.")]);
    $task = ripen(plantTask());

    expect($task->status)->toBe(TaskStatus::Stalled)
        ->and($task->stall_reason)->toBe("Interpret: I can't help with that request.")
        ->and(interpretRun($task)->attempt_count)->toBe(0);

    app(DeferTask::class)->handle($task);

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('gives up on a run that keeps being interrupted', function (): void {
    fakeAgent();
    $task = plantTask();
    $run = interpretRun($task);

    foreach (range(1, 3) as $interruption) {
        app(RunExecutor::class)->claim($run->id);
        travelSeconds(31);
        app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    }

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->interruption_count)->toBe(3)
        ->and($task->refresh()->status)->toBe(TaskStatus::Stalled)
        ->and(RunAttempt::query()->where('run_id', $run->id)->pluck('outcome')->unique()->all())->toBe([AttemptOutcome::Interrupted]);
    expectJournalConsistent($task);
});

it('stops a call in mid-stream when the task is paused and changes nothing', function (): void {
    fakeAgent(first: [static fn () => FakeReply::text('A long answer that never gets to finish.')->afterSilence(5)->onTick(2, static fn () => app(PauseTask::class)->handle(Task::query()->sole()))]);
    $task = plantTask();
    drainAgentQueue();
    $run = interpretRun($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Dormant)
        ->and($run->status)->toBe(RunStatus::Cancelled)
        ->and(RunAttempt::query()->where('run_id', $run->id)->sole()->outcome)->toBe(AttemptOutcome::Cancelled)
        ->and($task->interpreted_at)->toBeNull()
        ->and(app(GrowthCalculator::class)->forTask($task)->stage)->toBe(Stage::Seed);
    expectJournalConsistent($task);
});

it('stops writing as soon as the lease is lost in mid-stream, and the next attempt finishes the work', function (): void {
    fakeAgent(first: [static fn () => FakeReply::text('An answer that arrives too late.')->afterSilence(5)->onTick(2, static function (): void {
        travelSeconds(31);
        app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    })]);
    $task = ripen(plantTask());
    $attempts = RunAttempt::query()->where('run_id', interpretRun($task)->id)->orderBy('number')->get();

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($attempts->pluck('outcome')->all())->toBe([AttemptOutcome::Interrupted, AttemptOutcome::Succeeded])
        ->and($attempts->first()?->usage_estimated)->toBeTrue();
    expectJournalConsistent($task);
});

it('creates exactly one run when separate processes reconcile the same task at once', function (): void {
    $task = plantTask(defer: false);
    DB::transaction(static fn () => $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor));
    $startAt = (string) (microtime(true) + 1.5);

    $results = Process::pool(function (Pool $pool) use ($task, $startAt): void {
        foreach (range(1, 4) as $process) {
            $pool->path(base_path())->env(processEnvironment())->command([PHP_BINARY, 'tests/Support/reconcile-task.php', $task->id, $startAt]);
        }
    })->start()->wait();

    foreach ($results as $result) {
        expect($result->successful())->toBeTrue($result->errorOutput());
    }

    expect(AgentRun::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'agent')->count())->toBe(1)
        ->and(TaskEvent::query()->where('type', TaskEventType::RunScheduled)->count())->toBe(1);
    expectJournalConsistent($task);
});

it('makes a commit that races the sweeper wait for the lock and respect its decision', function (): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    $task = plantTask();
    [$claim, $result, $response] = claimAndAnswer(interpretRun($task));

    $holder = Process::path(base_path())->env(processEnvironment())->start([PHP_BINARY, 'tests/Support/expire-under-lock.php', $claim->run->id, '1.0']);
    $deadline = microtime(true) + 10;

    while (! str_contains($holder->output(), 'locked') && microtime(true) < $deadline) {
        usleep(20_000);
    }

    $startedAt = microtime(true);
    app(RunExecutor::class)->commit($claim, $result, $response);
    $waited = microtime(true) - $startedAt;

    expect($holder->wait()->successful())->toBeTrue($holder->errorOutput())
        ->and($waited)->toBeGreaterThan(0.5)
        ->and($claim->attempt->refresh()->outcome)->toBe(AttemptOutcome::LeaseLost)
        ->and($task->refresh()->interpreted_at)->toBeNull()
        ->and(Question::query()->count())->toBe(0);

    app(Sweeper::class)->expireLeases(CarbonImmutable::now());

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('keeps renewing its lease while the model is silent and recovers when the worker is killed', function (): void {
    $task = plantTask();
    $run = interpretRun($task);

    $worker = Process::path(base_path())->env(processEnvironment([
        'RIPE_INFERENCE_DRIVER' => 'fake',
        'RIPE_FAKE_PAUSE' => '30',
        'RIPE_LEASE_TTL' => '2',
        'RIPE_LEASE_RENEW_INTERVAL' => '0.3',
        'RIPE_LEASE_SAFETY_MARGIN' => '0.3',
    ]))->start([PHP_BINARY, 'artisan', 'queue:work', 'agent', '--queue=agent', '--once', '--timeout=60']);

    $deadline = microtime(true) + 20;

    do {
        usleep(200_000);
        $run->refresh();
        $renewedPastFirstLease = $run->status === RunStatus::Running
            && $run->started_at !== null
            && $run->lease_expires_at !== null
            && $run->lease_expires_at->greaterThan($run->started_at->addSeconds(4));
    } while (! $renewedPastFirstLease && microtime(true) < $deadline && $worker->running());

    expect($renewedPastFirstLease)->toBeTrue($worker->errorOutput().$worker->output());

    $worker->signal(SIGKILL);
    $worker->wait();

    while (CarbonImmutable::now()->lessThanOrEqualTo($run->refresh()->lease_expires_at ?? CarbonImmutable::now())) {
        usleep(100_000);
    }

    expect(app(Sweeper::class)->expireLeases(CarbonImmutable::now()))->toBe(1)
        ->and($run->refresh()->status)->toBe(RunStatus::Queued)
        ->and($run->interruption_count)->toBe(1);

    fakeAgent();

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe)
        ->and(RunAttempt::query()->where('run_id', $run->id)->orderBy('number')->pluck('outcome')->all())->toBe([AttemptOutcome::Interrupted, AttemptOutcome::Succeeded])
        ->and(ArtifactVersion::query()->count())->toBe(4);
    expectJournalConsistent($task);
});
