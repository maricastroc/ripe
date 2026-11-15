<?php

declare(strict_types=1);

use App\Actions\Tasks\HarvestTask;
use App\Actions\Tasks\ReplantTask;
use App\Actions\Tasks\RequestRevision;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Models\AgentRun;
use App\Models\Artifact;

it('harvests a growing task by cancelling its runs, and replants it dormant', function (): void {
    fakeAgent();
    $task = plantTask();
    drainAgentQueue(1);
    app(HarvestTask::class)->handle($task);
    app(HarvestTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Harvested)
        ->and(AgentRun::query()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->count())->toBe(0)
        ->and(collect(eventTypes($task))->filter(static fn (string $type): bool => $type === 'task.harvested')->count())->toBe(1);

    app(ReplantTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Dormant);
    expectJournalConsistent($task);
});

it('refuses revisions that cannot happen', function (): void {
    fakeAgent();
    $task = ripen(plantTask());
    $piece = firstPiece($task);
    $revise = static fn (?string $instruction = 'Shorter, please.') => app(RequestRevision::class)->handle($piece, $instruction);

    expect(fn () => $revise('   '))->toThrow(TaskActionRejected::class, 'Say what you want to change.');

    $revise();

    expect(fn () => $revise())->toThrow(TaskActionRejected::class, 'This piece is already being rewritten.');

    ripen($task);
    config(['ripe.agent.run_budget' => AgentRun::query()->where('task_id', $task->id)->count()]);

    expect(fn () => $revise())->toThrow(TaskActionRejected::class, 'This task used all 7 of its agent runs.');

    config(['ripe.agent.run_budget' => 24]);
    app(HarvestTask::class)->handle($task);

    expect(fn () => $revise())->toThrow(TaskActionRejected::class, 'Replant the task before asking for rewrites.')
        ->and(fn () => app(RequestRevision::class)->handle(Artifact::factory()->create()))->toThrow(TaskActionRejected::class, 'There is nothing to rewrite yet.');
    expectJournalConsistent($task);
});
