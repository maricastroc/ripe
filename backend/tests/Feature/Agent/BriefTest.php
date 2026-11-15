<?php

declare(strict_types=1);

use App\Agent\Brief;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\Task;

it('reads the brief of a task, with questions numbered by position', function (): void {
    $task = Task::factory()->interpreted()->create(['context' => ['Budget is tight.'], 'assumptions' => ['Plain words.'], 'language' => 'pt-BR']);
    Question::factory()->for($task)->at(1)->essential()->create(['text' => 'Second?']);
    Question::factory()->for($task)->at(0)->answered('Yes')->create(['text' => 'First?']);

    $brief = Brief::fromTask($task);

    expect($brief->language)->toBe('pt-BR')
        ->and($brief->context)->toBe(['Budget is tight.'])
        ->and(array_map(static fn ($question): array => [$question->number, $question->text, $question->kind, $question->status], $brief->questions))->toBe([
            [1, 'First?', QuestionKind::Optional, QuestionStatus::Answered],
            [2, 'Second?', QuestionKind::Essential, QuestionStatus::Open],
        ]);
});
