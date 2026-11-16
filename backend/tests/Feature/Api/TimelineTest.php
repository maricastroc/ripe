<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Inference\Drivers\Fake\FakeReply;
use App\Models\RunAttempt;
use App\Orchestration\Execution\RunExecutor;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;

const TIMELINE_ATTEMPT_KEYS = ['id', 'number', 'outcome', 'savedWork', 'startedAt', 'finishedAt', 'waitedMs', 'durationMs', 'firstTokenMs', 'promptTokens', 'completionTokens', 'usageEstimated', 'driver', 'model', 'costMicrousd', 'error'];

it('shows every attempt, marks saved work only where a result was saved, and exposes no raw error or internal field', function (): void {
    fakeAgent(first: [static fn (): FakeReply => FakeReply::text('Not JSON: the secret quince ratio is 7 to 3.')]);
    [, $token] = visitor();
    $task = apiTask($token);
    app(RunExecutor::class)->claim(interpretRun($task)->id);
    travelSeconds(120);
    app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);

    $response = api($token)->getJson("/api/tasks/{$task->id}/timeline")->assertOk();
    $attempts = collect($response->json('timeline.runs'))->flatMap(static fn (array $run): array => $run['attempts']);
    $stored = RunAttempt::query()->whereHas('run', static fn ($query) => $query->where('task_id', $task->id))->get();

    expect($attempts->pluck('id')->sort()->values()->all())->toBe($stored->pluck('id')->sort()->values()->all())
        ->and($attempts->pluck('outcome')->unique()->sort()->values()->all())->toBe(['interrupted', 'invalid_output', 'succeeded'])
        ->and($attempts->every(static fn (array $attempt): bool => array_keys($attempt) === TIMELINE_ATTEMPT_KEYS))->toBeTrue()
        ->and($attempts->every(static fn (array $attempt): bool => $attempt['savedWork'] === ($attempt['outcome'] === 'succeeded')))->toBeTrue()
        ->and($attempts->pluck('error')->filter()->unique()->sort()->values()->all())->toBe([
            'The model’s answer was not in the expected shape.',
            'The work stopped halfway and was picked up again.',
        ])
        ->and($response->json('timeline.totals.attempts'))->toBe($stored->count())
        ->and($response->json('timeline.totals.savedWork'))->toBe($stored->where('outcome.value', 'succeeded')->count())
        ->and((string) $response->getContent())->not->toContain('quince')->not->toContain('InvalidModelOutput')->not->toContain('lease')->not->toContain($stored->first()?->worker ?? 'no worker');
});
