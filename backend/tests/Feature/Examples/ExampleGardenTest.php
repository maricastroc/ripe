<?php

declare(strict_types=1);

use App\Actions\Tasks\DeferTask;
use App\Actions\Tasks\RequestRevision;
use App\Enums\ArtifactKind;
use App\Enums\QuestionStatus;
use App\Enums\Stage;
use App\Enums\TaskStatus;
use App\Examples\CommunityGarden;
use App\Examples\ExampleGarden;
use App\Exceptions\TaskActionRejected;
use App\Growth\GrowthCalculator;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\Visitor;

it('grows the community garden example through the real pipeline and keeps it read-only', function (): void {
    $example = app(ExampleGarden::class)->ensure();
    $pieces = Artifact::query()->where('task_id', $example->id)->where('kind', ArtifactKind::Step)->with('currentVersion')->get();

    expect($example->is_example)->toBeTrue()
        ->and($example->visitor_id)->toBeNull()
        ->and($example->status)->toBe(TaskStatus::Ripe)
        ->and($example->title)->toBe('Community garden proposal')
        ->and($example->prompt)->toBe(CommunityGarden::PROMPT)
        ->and(app(GrowthCalculator::class)->forTask($example)->stage)->toBe(Stage::Ripe)
        ->and($pieces->pluck('title')->all())->toBe(['Proposal outline', 'Draft: problem and goals', 'Budget and timeline', 'Full proposal'])
        ->and($pieces->last()?->currentVersion?->content)->toContain('[[TBD: name and contact of the person signing]]')
        ->and(Question::query()->where('task_id', $example->id)->orderBy('position')->pluck('status')->all())->toBe([QuestionStatus::Skipped, QuestionStatus::Open])
        ->and(Artifact::query()->where('task_id', $example->id)->where('kind', ArtifactKind::Review)->exists())->toBeTrue()
        ->and(RunAttempt::query()->pluck('driver')->unique()->all())->toBe(['fake'])
        ->and(Visitor::query()->count())->toBe(0);
    expectJournalConsistent($example);

    expect(fn () => app(DeferTask::class)->handle($example))->toThrow(TaskActionRejected::class)
        ->and(fn () => app(RequestRevision::class)->handle($pieces->first() ?? throw new RuntimeException, 'Shorter.'))->toThrow(TaskActionRejected::class);
});

it('grows the example only once', function (): void {
    $first = app(ExampleGarden::class)->ensure();
    $second = app(ExampleGarden::class)->ensure();

    expect($second->id)->toBe($first->id)
        ->and(Task::query()->where('is_example', true)->count())->toBe(1);
});

it('leaves nothing for the queue to do once the example is grown', function (): void {
    $example = app(ExampleGarden::class)->ensure();
    $events = $example->version;

    drainAgentQueue();

    expect($example->refresh()->version)->toBe($events)
        ->and(RunAttempt::query()->count())->toBe(7);
});
