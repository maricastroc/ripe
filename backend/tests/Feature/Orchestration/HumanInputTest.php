<?php

declare(strict_types=1);

use App\Actions\Tasks\AnswerQuestion;
use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\RunKind;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\Step;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

function stepStatuses(Task $task): array
{
    return $task->steps()->get()->mapWithKeys(static fn (Step $step): array => [$step->title => $step->status->value])->all();
}

function questionNumbered(Task $task, int $number): Question
{
    return Question::query()->where('task_id', $task->id)->where('position', $number - 1)->sole();
}

it('keeps writing the independent steps while one step waits for an answer', function (): void {
    fakeAgent([question('Do you accept the new deadline?')], [step('Key points'), step('Tone options'), step('Reply', [1, 2], [1])]);
    $task = ripen(plantTask());

    expect($task->status)->toBe(TaskStatus::Waiting)
        ->and(stepStatuses($task))->toBe(['Key points' => 'done', 'Tone options' => 'done', 'Reply' => 'blocked'])
        ->and(AgentRun::query()->where('kind', RunKind::Review)->exists())->toBeFalse();
    expectJournalConsistent($task);

    app(AnswerQuestion::class)->handle(questionNumbered($task, 1), 'Yes, if the scope stays the same.');
    $task = ripen($task);

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and(stepStatuses($task))->toBe(['Key points' => 'done', 'Tone options' => 'done', 'Reply' => 'done']);
    expectJournalConsistent($task);
});

it('holds back the steps that build on a blocked step, and only those', function (): void {
    fakeAgent([question('Which venue?')], [step('Venue options', [], [1]), step('Budget sketch'), step('Invitation', [1])]);
    $task = ripen(plantTask());

    expect(stepStatuses($task))->toBe(['Venue options' => 'blocked', 'Budget sketch' => 'done', 'Invitation' => 'blocked'])
        ->and($task->status)->toBe(TaskStatus::Waiting);
    expectJournalConsistent($task);
});

it('never lets optional questions block a step', function (): void {
    fakeAgent([question('What is the neighbour called?', 'optional')], [step('Note', [], [1]), step('Final note', [1], [1])]);
    $task = ripen(plantTask());

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and(DB::table('step_requirements')->count())->toBe(0);
    expectJournalConsistent($task);
});

it('writes everything with markers and holds only the review for a completion condition', function (): void {
    fakeAgent([question('What is the trip budget?')], [step('Destinations'), step('Itinerary', [1]), step('Final plan', [1, 2])]);
    $task = ripen(plantTask());

    expect($task->status)->toBe(TaskStatus::Waiting)
        ->and(stepStatuses($task))->toBe(['Destinations' => 'done', 'Itinerary' => 'done', 'Final plan' => 'done'])
        ->and(AgentRun::query()->where('kind', RunKind::Review)->exists())->toBeFalse();

    $budget = questionNumbered($task, 1);
    app(AnswerQuestion::class)->handle($budget, 'Around 2,000 euros.');
    $task = ripen($task);

    $refreshes = AgentRun::query()->where('kind', RunKind::Revise)->get();
    $review = $task->artifacts()->where('kind', 'review')->sole()->currentVersion()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($refreshes)->toHaveCount(3)
        ->and($refreshes->every(static fn (AgentRun $run): bool => $run->isAutomatic() && $run->instruction === null))->toBeTrue()
        ->and($review->inputs['questions'][$budget->id])->toBe(['status' => 'answered', 'revision' => 1]);
    expectJournalConsistent($task);
});

it('refreshes only the pieces written by the agent before the answer arrived', function (): void {
    fakeAgent([question('Who signs the proposal?')], [step('Outline'), step('Draft'), step('Signature block', [], [1])]);
    $task = ripen(plantTask());

    $draft = Artifact::query()->whereHas('step', static fn ($query) => $query->where('title', 'Draft'))->sole();
    DB::transaction(fn () => $draft->addVersion('My own rewrite of the draft, which the agent must not overwrite on its own initiative.', ArtifactVersionSource::UserEdit, EventActor::Visitor));

    app(AnswerQuestion::class)->handle(questionNumbered($task, 1), 'Ana Lima, coordinator.');
    $task = ripen($task);

    $refreshed = AgentRun::query()->where('kind', RunKind::Revise)->with('artifact.step')->get()->map(static fn (AgentRun $run): ?string => $run->artifact?->step?->title)->all();

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($refreshed)->toBe(['Outline']);
    expectJournalConsistent($task);
});

it('lets a skipped question release its step and the completion, keeping the marker', function (): void {
    fakeAgent([question('Accept the deadline?')], [step('Points'), step('Reply', [1], [1])]);
    $task = ripen(plantTask());

    app(AnswerQuestion::class)->skip(questionNumbered($task, 1));
    $task = ripen($task);

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and(AgentRun::query()->where('kind', RunKind::Revise)->exists())->toBeFalse();
    expectJournalConsistent($task);
});

it('changes only future scheduling when a question is reopened', function (): void {
    fakeAgent([question('Accept the deadline?')], [step('Points'), step('Tone'), step('Reply', [1, 2], [1])]);
    $task = ripen(plantTask());
    $deadline = questionNumbered($task, 1);

    app(AnswerQuestion::class)->handle($deadline, 'Yes.');
    app(AnswerQuestion::class)->reopen($deadline);

    expect(stepStatuses($task->refresh()))->toBe(['Points' => 'done', 'Tone' => 'done', 'Reply' => 'blocked'])
        ->and(AgentRun::query()->where('kind', RunKind::Generate)->where('status', 'cancelled')->count())->toBe(1)
        ->and($task->status)->toBe(TaskStatus::Waiting);

    app(AnswerQuestion::class)->handle($deadline, 'Yes, with conditions.');
    $task = ripen($task);
    app(AnswerQuestion::class)->reopen($deadline);

    expect($task->refresh()->status)->toBe(TaskStatus::Ripe)
        ->and($task->steps()->where('status', StepStatus::Done)->count())->toBe(3);
    expectJournalConsistent($task);
});
