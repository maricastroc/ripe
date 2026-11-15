<?php

declare(strict_types=1);

namespace App\Agent;

use App\Enums\Deliverable;
use App\Models\Question;
use App\Models\Task;

final readonly class Brief
{
    /**
     * @param  list<string>  $context
     * @param  list<string>  $assumptions
     * @param  list<BriefQuestion>  $questions
     */
    public function __construct(
        public string $prompt,
        public string $title,
        public ?string $outcome = null,
        public ?Deliverable $deliverable = null,
        public array $context = [],
        public array $assumptions = [],
        public array $questions = [],
        public ?string $language = null,
    ) {}

    public static function fromTask(Task $task): self
    {
        return new self(
            prompt: $task->prompt,
            title: $task->title,
            outcome: $task->outcome,
            deliverable: $task->deliverable,
            context: $task->context,
            assumptions: $task->assumptions,
            questions: array_values($task->questions()->get()->map(static fn (Question $question): BriefQuestion => new BriefQuestion(
                number: $question->position + 1,
                text: $question->text,
                kind: $question->kind,
                status: $question->status,
                answer: $question->answer,
            ))->all()),
            language: $task->language,
        );
    }
}
