<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Question;
use App\Models\Visitor;
use Illuminate\Auth\Access\Response;

final readonly class QuestionPolicy
{
    public function __construct(private TaskPolicy $tasks) {}

    public function update(?Visitor $visitor, Question $question): Response
    {
        return $this->tasks->update($visitor, $question->owningTask());
    }
}
