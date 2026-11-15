<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Questions;

use App\Actions\Tasks\AnswerQuestion;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\JsonResponse;

final class ReopenQuestionController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(Question $question, AnswerQuestion $answers): JsonResponse
    {
        $task = $question->owningTask();

        $answers->reopen($question);

        return $this->task($task);
    }
}
