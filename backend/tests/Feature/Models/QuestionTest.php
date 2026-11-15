<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use App\Enums\TaskEventType;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

it('counts every new answer as a revision', function (): void {
    $question = Question::factory()->create();

    DB::transaction(fn () => $question->answer('  Ana  '));
    DB::transaction(fn () => $question->answer('Ana'));
    DB::transaction(fn () => $question->answer('Ana Lúcia'));

    expect($question->fresh())
        ->status->toBe(QuestionStatus::Answered)
        ->answer->toBe('Ana Lúcia')
        ->answer_revision->toBe(2)
        ->resolved_at->not->toBeNull();

    expect($question->owningTask()->events()->pluck('payload')->all())->toEqual([
        ['question_id' => $question->id, 'revision' => 1],
        ['question_id' => $question->id, 'revision' => 2],
    ]);
});

it('reopens without losing its revision history', function (): void {
    $question = Question::factory()->answered('Monday')->create();

    DB::transaction(fn () => $question->reopen());
    expect($question->fresh())
        ->status->toBe(QuestionStatus::Open)
        ->answer->toBeNull()
        ->resolved_at->toBeNull()
        ->answer_revision->toBe(1);

    DB::transaction(fn () => $question->answer('Tuesday'));
    expect($question->fresh()?->answer_revision)->toBe(2);

    expect($question->owningTask()->events()->pluck('type')->all())->toBe([
        TaskEventType::QuestionReopened, TaskEventType::QuestionAnswered,
    ]);
});
