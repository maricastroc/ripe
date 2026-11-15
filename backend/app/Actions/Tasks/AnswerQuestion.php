<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Question;
use App\Orchestration\TaskOrchestrator;
use Illuminate\Support\Facades\DB;

final class AnswerQuestion
{
    use LocksTasks;

    public function __construct(private readonly TaskOrchestrator $orchestrator) {}

    public function handle(Question $question, string $answer): void
    {
        $this->change($question, static fn (Question $question) => $question->answer($answer));
    }

    public function skip(Question $question): void
    {
        $this->change($question, static fn (Question $question) => $question->skip());
    }

    public function reopen(Question $question): void
    {
        $this->change($question, static fn (Question $question) => $question->reopen());
    }

    /** @param callable(Question): void $change */
    private function change(Question $question, callable $change): void
    {
        DB::transaction(function () use ($question, $change): void {
            $task = $this->locked($question->owningTask());
            $fresh = Question::query()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $fresh->setRelation('task', $task);

            $change($fresh);
            $this->orchestrator->reconcile($task);
        });
    }
}
