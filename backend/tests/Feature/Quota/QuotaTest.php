<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\Inference\RateLimited;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\InferenceManager;
use App\Inference\ModelRequest;
use App\Inference\QuotaLimits;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\RunAttempt;
use App\Models\TaskEvent;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\LimitedDriver;

it('admits no more calls than the quota allows when workers race for it', function (): void {
    $startAt = (string) (microtime(true) + 1.5);

    $results = Process::pool(function (Pool $pool) use ($startAt): void {
        foreach (range(1, 8) as $process) {
            $pool->path(base_path())->env(['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'CACHE_STORE' => 'array'])
                ->command([PHP_BINARY, 'tests/Support/admit-quota.php', '3', '100000', '1000', $startAt]);
        }
    })->start()->wait();

    $outcomes = collect($results)->map(static fn ($result): string => trim($result->output()))->countBy()->sortKeys()->all();

    expect($outcomes)->toBe(['admitted' => 3, 'refused' => 5])
        ->and(DB::table('inference_calls')->where('scope', 'race:model')->count())->toBe(3);
});

it('rests when the free quota is spent, spends no attempt waiting, and resumes without redoing work', function (): void {
    $limited = new LimitedDriver(fakeAgent(), new QuotaLimits(100, 3, 1_000_000, 10_000_000, 1));

    app(InferenceManager::class)->using($limited, function () use ($limited): void {
        [, $token] = visitor();
        $task = apiTask($token);
        drainAgentQueue();

        $waiting = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Queued)->sole();
        $agent = api($token)->getJson('/api/agent')->assertOk();

        expect($limited->calls)->toBe(3)
            ->and($waiting->deferred_reason)->toBe(RunDeferral::Quota)
            ->and(RunAttempt::query()->where('run_id', $waiting->id)->count())->toBe(0)
            ->and($agent->json('mode'))->toBe('resting')
            ->and($agent->json('rest'))->toBe('daily')
            ->and($agent->json('resumesAt'))->toBeNull();

        travelSeconds(31 * 60);
        drainAgentQueue();

        expect($limited->calls)->toBe(3)
            ->and(TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::QuotaDeferred)->count())->toBe(1);

        travelSeconds(24 * 3600);
        $task = ripen($task);

        $generated = AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Generate)->get();

        expect($task->status)->toBe(TaskStatus::Ripe)
            ->and($generated->every(static fn (AgentRun $run): bool => $run->status === RunStatus::Succeeded && RunAttempt::query()->where('run_id', $run->id)->count() === 1))->toBeTrue()
            ->and(ArtifactVersion::query()->whereIn('run_id', $generated->pluck('id'))->where('source', ArtifactVersionSource::Generate)->count())->toBe($generated->count())
            ->and($limited->calls)->toBe(AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Succeeded)->count());
        expectJournalConsistent($task);
    });
});

it('waits as long as Groq asks before any worker calls it again', function (): void {
    $limited = new LimitedDriver(fakeAgent(first: [
        static fn (ModelRequest $request): FakeReply => FakeReply::failure(new RateLimited('Rate limit reached on requests per minute (RPM).', 120.0)),
    ]), new QuotaLimits(100, 1000, 1_000_000, 10_000_000, 1));

    app(InferenceManager::class)->using($limited, function () use ($limited): void {
        $first = plantTask();
        $second = plantTask();
        drainAgentQueue();

        expect($limited->calls)->toBe(1)
            ->and(interpretRun($second)->refresh()->deferred_reason)->toBe(RunDeferral::Quota)
            ->and(interpretRun($first)->refresh()->attempt_count)->toBe(0);

        travelSeconds(60);
        drainAgentQueue();
        expect($limited->calls)->toBe(1);

        travelSeconds(300);
        $first = ripen($first);
        $second = ripen($second);

        expect($first->status)->toBe(TaskStatus::Ripe)
            ->and($second->status)->toBe(TaskStatus::Ripe)
            ->and(AgentRun::query()->where('status', RunStatus::Failed)->count())->toBe(0);
        expectJournalConsistent($first);
        expectJournalConsistent($second);
    });
});
