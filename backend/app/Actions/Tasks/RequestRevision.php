<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Agent\Text\Markdown;
use App\Enums\ArtifactKind;
use App\Enums\EventActor;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Task;
use App\Orchestration\NextRun;
use App\Orchestration\RunBudget;
use App\Orchestration\RunDispatcher;
use Illuminate\Support\Facades\DB;

final class RequestRevision
{
    use LocksTasks;

    public function __construct(
        private readonly RunDispatcher $dispatcher,
        private readonly RunBudget $budget,
    ) {}

    public function handle(Artifact $artifact, ?string $instruction = null): AgentRun
    {
        $clean = $instruction === null ? null : mb_substr(trim($instruction), 0, 500);

        if ($clean === '') {
            throw new TaskActionRejected('Say what you want to change.');
        }

        return DB::transaction(function () use ($artifact, $clean): AgentRun {
            $task = $this->locked($artifact->owningTask());

            return $this->within($task, $artifact, $clean);
        });
    }

    public function refreshWithAnswers(Task $task): int
    {
        return DB::transaction(function () use ($task): int {
            $task = $this->locked($task);
            $count = 0;

            foreach ($task->artifacts()->where('kind', ArtifactKind::Step)->with('currentVersion')->get() as $artifact) {
                if ($artifact->currentVersion !== null && Markdown::gaps($artifact->currentVersion->content) !== [] && ! $this->isBeingRevised($artifact)) {
                    $this->within($task, $artifact, null);
                    $count++;
                }
            }

            if ($count === 0) {
                throw new TaskActionRejected('None of the pieces has gaps left to fill.');
            }

            return $count;
        });
    }

    private function within(Task $task, Artifact $artifact, ?string $instruction): AgentRun
    {
        if ($task->is_example) {
            throw new TaskActionRejected('The agent does not work on examples.');
        }

        if ($task->status === TaskStatus::Harvested) {
            throw new TaskActionRejected('Replant the task before asking for rewrites.');
        }

        if ($this->isBeingRevised($artifact)) {
            throw new TaskActionRejected('This piece is already being rewritten.');
        }

        if ($this->budget->remaining($task) < 1) {
            throw new TaskActionRejected("This task used all {$this->budget->limit()} of its agent runs.");
        }

        $version = $artifact->current_version_id ?? throw new TaskActionRejected('There is nothing to rewrite yet.');
        $run = $this->dispatcher->schedule($task, NextRun::revision($artifact, $version, $instruction), EventActor::Visitor);
        $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor);

        return $run;
    }

    private function isBeingRevised(Artifact $artifact): bool
    {
        return AgentRun::query()
            ->where('artifact_id', $artifact->id)
            ->where('kind', RunKind::Revise)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists();
    }
}
