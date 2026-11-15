<?php

declare(strict_types=1);

use App\Enums\RunKind;
use App\Enums\TaskStatus;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\ModelRequest;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Orchestration\Execution\RunExecutor;
use App\Orchestration\Output\LiveText;
use App\Orchestration\Output\PartialOutput;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function generateRun(): AgentRun
{
    return AgentRun::query()->where('kind', RunKind::Generate)->whereNotNull('lease_token')->sole();
}

function watchedPiece(Closure $watch, int $tick = 6, string $text = 'Dear neighbour, thank you for watering my tomatoes and basil while I was away last week.'): Closure
{
    return static fn (ModelRequest $request): ?FakeReply => $request->schema === null
        ? FakeReply::text($text)->onTick($tick, $watch)
        : null;
}

it('shows the text the running attempt has written so far', function (): void {
    $seen = [];
    fakeAgent(first: [
        static fn (): ?FakeReply => null,
        static fn (): ?FakeReply => null,
        watchedPiece(static function () use (&$seen): void {
            $seen[] = app(PartialOutput::class)->current(generateRun());
        }),
    ]);
    $task = ripen(plantTask());

    expect($seen[0])->toBeInstanceOf(LiveText::class)
        ->and($seen[0]?->text)->toBe('Dear neighbour, thank you for ')
        ->and($seen[0]?->attemptNumber)->toBe(1)
        ->and($task->status)->toBe(TaskStatus::Ripe);
});

it('records no live text for structured answers', function (): void {
    fakeAgent();
    $run = interpretRun(plantTask());
    $claim = app(RunExecutor::class)->claim($run->id) ?? throw new RuntimeException('The run could not be claimed.');

    expect(app(PartialOutput::class)->open($claim->run, $claim->attempt))->toBeNull();
});

it('forgets the live text once the attempt ends', function (): void {
    $attemptId = null;
    fakeAgent(first: [
        static fn (): ?FakeReply => null,
        static fn (): ?FakeReply => null,
        watchedPiece(static function () use (&$attemptId): void {
            $attemptId = app(PartialOutput::class)->current(generateRun())?->attemptId;
        }),
    ]);
    ripen(plantTask());

    expect($attemptId)->toBeString()
        ->and(Cache::has("ripe:output:attempt:{$attemptId}"))->toBeFalse();
});

it('never lets an older attempt overwrite or show over a newer one', function (): void {
    $seen = [];
    fakeAgent(first: [
        static fn (): ?FakeReply => null,
        static fn (): ?FakeReply => null,
        watchedPiece(static function (): void {
            travelSeconds(31);
            app(Sweeper::class)->expireLeases(CarbonImmutable::now());
        }, 4, 'An old attempt that keeps talking after its lease is gone, on and on.'),
        watchedPiece(static function () use (&$seen): void {
            $run = generateRun();
            $old = RunAttempt::query()->where('run_id', $run->id)->where('number', 1)->sole();
            $seen['old write accepted'] = app(PartialOutput::class)->write($run->id, $old->id, 1, 'Stale words from the first attempt.');
            $seen['old text'] = app(PartialOutput::class)->of($old);
            $seen['current'] = app(PartialOutput::class)->current($run);
        }),
    ]);
    $task = ripen(plantTask());

    expect($seen['old write accepted'])->toBeFalse()
        ->and($seen['old text'])->toBeNull()
        ->and($seen['current']?->attemptNumber)->toBe(2)
        ->and($seen['current']?->text)->toBe('Dear neighbour, thank you for ')
        ->and($task->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('lets abandoned live text expire on its own', function (): void {
    fakeAgent();
    $task = plantTask();
    drainAgentQueue(2);
    $run = AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Generate)->sole();
    $claim = app(RunExecutor::class)->claim($run->id) ?? throw new RuntimeException('The run could not be claimed.');
    $writer = app(PartialOutput::class)->open($claim->run, $claim->attempt);
    $writer?->onDelta('Words from a worker that died.');

    expect(app(PartialOutput::class)->current($run->refresh())?->text)->toBe('Words from a worker that died.');

    travelSeconds(601);

    expect(app(PartialOutput::class)->of($claim->attempt))->toBeNull();
});

it('stops writing once a newer attempt has taken over', function (): void {
    fakeAgent();
    $task = plantTask();
    drainAgentQueue(2);
    $run = AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Generate)->sole();
    $first = app(RunExecutor::class)->claim($run->id) ?? throw new RuntimeException('The run could not be claimed.');
    $writer = app(PartialOutput::class)->open($first->run, $first->attempt) ?? throw new RuntimeException('The run could not be claimed.');
    $writer->onDelta('First attempt. ');

    travelSeconds(31);
    app(Sweeper::class)->expireLeases(CarbonImmutable::now());
    $second = app(RunExecutor::class)->claim($run->id) ?? throw new RuntimeException('The run could not be claimed.');
    app(PartialOutput::class)->open($second->run, $second->attempt)?->onDelta('Second attempt.');
    $writer->onDelta('Still the first attempt.');

    expect($writer->isSuperseded())->toBeTrue()
        ->and(app(PartialOutput::class)->of($first->attempt))->toBeNull()
        ->and(app(PartialOutput::class)->current($run->refresh())?->text)->toBe('Second attempt.');
});
