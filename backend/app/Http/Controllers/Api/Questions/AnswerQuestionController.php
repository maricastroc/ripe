<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Questions;

use App\Actions\Tasks\AnswerQuestion;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerQuestionRequest;
use App\Models\Question;
use Illuminate\Http\JsonResponse;

final class AnswerQuestionController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(AnswerQuestionRequest $request, Question $question, AnswerQuestion $answers): JsonResponse
    {
        $task = $question->owningTask();

        $answers->handle($question, $request->string('answer')->toString());

        return $this->task($task);
    }
}
