<?php

declare(strict_types=1);

use App\Actions\Tasks\DeferTask;
use App\Actions\Tasks\PauseTask;
use App\Enums\AttemptOutcome;
use App\Enums\Inference\TimeoutKind;
use App\Enums\RunStatus;
use App\Enums\Stage;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRefused;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\ProviderQuotaExhausted;
use App\Exceptions\Inference\RateLimited;
use App\Growth\GrowthCalculator;
use App\Inference\Drivers\Fake\FakeReply;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Random\Engine\Mt19937;
use Random\Randomizer;

function stageOf(Task $task): Stage
{
    return app(GrowthCalculator::class)->forTask($task->refresh())->stage;
}

function stageChanges(Task $task): int
{
    return TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::PlantStageChanged)->count();
}

/** @return list<Stage> */
function ripenWatchingGrowth(Task $task, int $rounds = 400): array
{
    $stages = [];

    for ($round = 0; $round < $rounds; $round++) {
        $job = Queue::connection('agent')->pop('agent');

        if ($job !== null) {
            $job->fire();
            $stages[] = stageOf($task);

            continue;
        }

        $task->refresh();

        if (in_array($task->status, [TaskStatus::Stalled, TaskStatus::Dormant], true)) {
            app(DeferTask::class)->handle($task);

            continue;
        }

        $next = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Queued)->min('available_at');

        if (! is_string($next)) {
            break;
        }

        Date::setTestNow(CarbonImmutable::parse($next)->addSecond());
    }

    return $stages;
}

/** @return list<Closure(): ?FakeReply|InferenceException> */
function randomFailureScript(int $seed, int $calls = 40): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $failures = [
        static fn (): ModelTimeout => new ModelTimeout(TimeoutKind::Idle),
        static fn (): ModelCallFailed => new ModelCallFailed('The connection dropped.'),
        static fn (): InvalidModelOutput => new InvalidModelOutput('The reply was not valid JSON.'),
        static fn (): RateLimited => new RateLimited('Slow down.', 3.0),
        static fn (): ModelUnavailable => new ModelUnavailable('The model is overloaded.', 2.0),
        static fn (): Closure => static fn (): FakeReply => FakeReply::text('Too short.'),
        static fn (): Closure => static fn (): FakeReply => FakeReply::text('An answer that arrives too late.')->afterSilence(4)->onTick(2, static function (): void {
            travelSeconds(31);
            app(Sweeper::class)->expireLeases(CarbonImmutable::now());
        }),
        static fn (): Closure => static fn (): FakeReply => FakeReply::text('An answer nobody waits for.')->afterSilence(4)->onTick(2, static fn () => app(PauseTask::class)->handle(Task::query()->sole())),
    ];
    $script = [];

    for ($call = 0; $call < $calls; $call++) {
        $script[] = $randomizer->getInt(0, 99) < 45
            ? $failures[$randomizer->getInt(0, count($failures) - 1)]()
            : static fn (): ?FakeReply => null;
    }

    return $script;
}

it('leaves growth exactly where it was when a call fails', function (Closure|InferenceException $failure): void {
    fakeAgent(first: [static fn (): ?FakeReply => null, static fn (): ?FakeReply => null, $failure]);
    $task = plantTask();
    drainAgentQueue();

    expect(stageOf($task))->toBe(Stage::Leafing)
        ->and(stageChanges($task))->toBe(2)
        ->and(ArtifactVersion::query()->count())->toBe(0)
        ->and($task->steps()->where('status', StepStatus::Done)->count())->toBe(0);
    expectJournalConsistent($task);
})->with([
    'a timeout' => fn () => new ModelTimeout(TimeoutKind::Idle),
    'a dropped connection' => fn () => new ModelCallFailed('The connection dropped.'),
    'invalid output' => fn () => new InvalidModelOutput('The reply was not valid JSON.'),
    'a reply too short to be a piece' => fn () => static fn (): FakeReply => FakeReply::text('Too short.'),
    'a partial reply that breaks off' => fn () => static fn (): FakeReply => FakeReply::partialThenFailure('Dear neighbour, thank you so much for', new ModelCallFailed('The stream ended early.')),
    'a rate limit' => fn () => new RateLimited('Slow down.', 7.0),
    'an overloaded model' => fn () => new ModelUnavailable('The model is overloaded.'),
    'a refusal' => fn () => new ModelRefused("I can't help with that request."),
    'a rejected request' => fn () => new ModelRequestRejected('The input is too long.'),
    'a misconfigured driver' => fn () => new DriverMisconfigured('Incorrect API key provided.'),
    'an exhausted quota' => fn () => new ProviderQuotaExhausted('You exceeded your current quota.'),
]);

it('records every change of stage together with the successful attempt that caused it', function (): void {
    fakeAgent(first: randomFailureScript(7));
    $task = plantTask();
    ripenWatchingGrowth($task);

    $changes = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::PlantStageChanged)->orderBy('sequence')->get();

    expect($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and($changes->map(static fn (TaskEvent $event): mixed => $event->payload['to'])->all())->toBe(['sprout', 'leafing', 'blossom', 'fruit', 'ripe']);

    foreach ($changes as $change) {
        $attempt = RunAttempt::query()->findOrFail($change->attempt_id);
        $succeeded = TaskEvent::query()->where('task_id', $task->id)->where('sequence', $change->sequence - 1)->sole();

        expect($attempt->outcome)->toBe(AttemptOutcome::Succeeded)
            ->and($succeeded->type)->toBe(TaskEventType::RunSucceeded)
            ->and($succeeded->attempt_id)->toBe($change->attempt_id);
    }

    expectJournalConsistent($task);
});

it('never moves growth with time alone', function (): void {
    fakeAgent([question('Which venue did you book?')], [step('Outline'), step('Venue options', requires: [1])]);
    $waiting = ripen(plantTask());
    $paused = plantTask();
    app(PauseTask::class)->handle($paused);
    $stage = stageOf($waiting);
    $changes = stageChanges($waiting);
    $versions = ArtifactVersion::query()->count();

    foreach (range(1, 30) as $day) {
        Date::setTestNow(CarbonImmutable::now()->addDay());
        app(Sweeper::class)->sweep();
        drainAgentQueue();
    }

    expect($waiting->refresh()->status)->toBe(TaskStatus::Waiting)
        ->and($stage)->toBe(Stage::Blossom)
        ->and(stageOf($waiting))->toBe($stage)
        ->and(stageChanges($waiting))->toBe($changes)
        ->and(ArtifactVersion::query()->count())->toBe($versions)
        ->and($paused->refresh()->status)->toBe(TaskStatus::Dormant)
        ->and(stageOf($paused))->toBe(Stage::Seed)
        ->and(RunAttempt::query()->whereIn('run_id', AgentRun::query()->where('task_id', $paused->id)->select('id'))->count())->toBe(0);
    expectJournalConsistent($waiting);
    expectJournalConsistent($paused);
});

it('only ever grows, and only through results it kept, whatever goes wrong along the way', function (int $seed): void {
    fakeAgent(first: randomFailureScript($seed));
    $task = plantTask();
    $stages = ripenWatchingGrowth($task);
    $indices = array_map(static fn (Stage $stage): int => $stage->index(), $stages);
    $sorted = $indices;
    sort($sorted);

    $orphans = ArtifactVersion::query()
        ->whereNotNull('run_id')
        ->whereNotIn('run_id', AgentRun::query()->where('status', RunStatus::Succeeded)->select('id'))
        ->count();
    $last = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::PlantStageChanged)->orderByDesc('sequence')->first();

    expect(RunAttempt::query()->where('outcome', '!=', AttemptOutcome::Succeeded)->exists())->toBeTrue()
        ->and($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and($indices)->toBe($sorted)
        ->and($orphans)->toBe(0)
        ->and($last?->payload['to'])->toBe(stageOf($task)->value)
        ->and(AgentRun::query()->where('status', RunStatus::Succeeded)->whereDoesntHave('attempts', static fn ($attempts) => $attempts->where('outcome', AttemptOutcome::Succeeded))->count())->toBe(0);
    expectJournalConsistent($task);
})->with(range(1, 12));
