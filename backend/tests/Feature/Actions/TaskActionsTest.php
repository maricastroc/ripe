<?php

declare(strict_types=1);

use App\Actions\Tasks\DeferTask;
use App\Actions\Tasks\HarvestTask;
use App\Actions\Tasks\PauseTask;
use App\Actions\Tasks\PlantTask;
use App\Actions\Tasks\ReplantTask;
use App\Actions\Tasks\RequestRevision;
use App\Agent\Limits;
use App\Enums\ArtifactVersionSource;
use App\Enums\RunKind;
use App\Enums\RunOrigin;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Visitor;
use Carbon\CarbonImmutable;

it('plants a task with a trimmed prompt and a provisional title', function (): void {
    fakeAgent();
    $task = app(PlantTask::class)->handle(Visitor::factory()->create(), "  Plan a surprise birthday dinner for my sister, who loves Lebanese food and hates crowds\nShe turns forty.  ");

    expect($task->prompt)->toStartWith('Plan a surprise')->toEndWith('forty.')
        ->and($task->title)->toBe('Plan a surprise birthday dinner for my sister, who loves Leba…')
        ->and(mb_strlen($task->title))->toBe(62)
        ->and($task->status)->toBe(TaskStatus::Ripening)
        ->and(array_slice(eventTypes($task), 0, 3))->toBe(['task.planted', 'task.deferred', 'task.status_changed'])
        ->and(AgentRun::query()->where('task_id', $task->id)->sole()->kind)->toBe(RunKind::Interpret);
    expectJournalConsistent($task);
});

it('keeps a task dormant when it is planted without being deferred', function (): void {
    $task = plantTask(defer: false);

    expect($task->status)->toBe(TaskStatus::Dormant)
        ->and(AgentRun::query()->count())->toBe(0)
        ->and(eventTypes($task))->toBe(['task.planted']);
    expectJournalConsistent($task);
});

it('refuses prompts that are too short or too long', function (string $prompt, string $message): void {
    expect(fn () => plantTask($prompt))->toThrow(TaskActionRejected::class, $message)
        ->and(Task::query()->count())->toBe(0);
})->with([
    'blank' => ['   ', 'Write a little more about the task.'],
    'two characters' => ['hi', 'Write a little more about the task.'],
    'too long' => fn () => [str_repeat('a', Limits::MAX_PROMPT_CHARACTERS + 1), 'Keep the task under 2000 characters.'],
]);

it('pauses a ripening task, withdrawing its queued run', function (): void {
    fakeAgent();
    $task = plantTask();
    app(PauseTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Dormant)
        ->and(interpretRun($task)->status)->toBe(RunStatus::Cancelled)
        ->and(drainAgentQueue())->toBe(1)
        ->and(interpretRun($task)->attempt_count)->toBe(0);
    expectJournalConsistent($task);
});

it('ignores a pause for a task that is not growing', function (): void {
    fakeAgent();
    $task = ripen(plantTask());
    $events = TaskEvent::query()->count();
    app(PauseTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and(TaskEvent::query()->count())->toBe($events);
});

it('resumes a paused task where it left off', function (): void {
    fakeAgent();
    $task = plantTask();
    drainAgentQueue(2);
    app(PauseTask::class)->handle($task);
    app(DeferTask::class)->handle($task);

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe)
        ->and(eventTypes($task))->toContain('task.paused', 'task.resumed')
        ->and(AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Interpret)->count())->toBe(1);
    expectJournalConsistent($task);
});

it('refuses to defer a task the agent cannot work on', function (Closure $task, string $message): void {
    expect(fn () => app(DeferTask::class)->handle($task()))->toThrow(TaskActionRejected::class, $message);
})->with([
    'an example' => [fn () => Task::factory()->example()->create(), 'The agent does not work on examples.'],
    'a ripe task' => [fn () => Task::factory()->ripe()->create(), 'This task is already ripe.'],
    'a harvested task' => [fn () => Task::factory()->harvested()->create(), 'This task has already been harvested.'],
]);

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

it('replants a harvested task as ripe when it had ripened', function (): void {
    fakeAgent();
    $task = ripen(plantTask());
    app(HarvestTask::class)->handle($task);
    app(ReplantTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and(eventTypes($task))->toContain('task.harvested', 'task.replanted');
    expectJournalConsistent($task);
});

it('rewrites a piece on request and ripens the task again', function (): void {
    fakeAgent();
    $task = ripen(plantTask());
    $piece = firstPiece($task);
    $before = $piece->current_version_id;
    config(['ripe.agent.pace' => 'calm']);
    $run = app(RequestRevision::class)->handle($piece, '  Make it warmer.  ');

    expect($run->origin)->toBe(RunOrigin::Visitor)
        ->and($run->available_at->lessThanOrEqualTo(CarbonImmutable::now()))->toBeTrue()
        ->and($task->refresh()->status)->toBe(TaskStatus::Ripening);

    $task = ripen($task);
    $version = $piece->refresh()->currentVersion;

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($version?->source)->toBe(ArtifactVersionSource::Revise)
        ->and($version?->instruction)->toBe('Make it warmer.')
        ->and($version?->parent_version_id)->toBe($before)
        ->and($version?->inputs['base_version_id'] ?? null)->toBe($before);
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

it('rewrites every piece that still has gaps once the visitor has answered', function (): void {
    fakeAgent();
    $task = ripen(plantTask());

    expect(app(RequestRevision::class)->refreshWithAnswers($task))->toBe(3)
        ->and(fn () => app(RequestRevision::class)->refreshWithAnswers($task))->toThrow(TaskActionRejected::class, 'None of the pieces has gaps left to fill.')
        ->and(ripen($task)->status)->toBe(TaskStatus::Ripe)
        ->and(AgentRun::query()->where('kind', RunKind::Revise)->where('status', RunStatus::Succeeded)->count())->toBe(3);
    expectJournalConsistent($task);
});
