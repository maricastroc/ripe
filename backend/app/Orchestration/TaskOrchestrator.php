<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\ArtifactKind;
use App\Enums\EventActor;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TaskOrchestrator
{
    public function __construct(
        private readonly RunDispatcher $dispatcher,
        private readonly AgentSettings $settings,
    ) {}

    public function reconcileTask(string $taskId): void
    {
        DB::transaction(function () use ($taskId): void {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task !== null) {
                $this->reconcile($task);
            }
        });
    }

    public function reconcile(Task $task): void
    {
        Transactions::require('Reconciling a task');

        if ($task->is_example || ! in_array($task->status, [TaskStatus::Ripening, TaskStatus::Waiting], true)) {
            return;
        }

        $steps = $task->steps()->with(['dependencies', 'requirements', 'artifact.currentVersion'])->get();
        $questions = $task->questions()->get();
        $active = AgentRun::query()->where('task_id', $task->id)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->get();

        $active = $this->refreshReadiness($task, $steps, $active);

        if (! $active->contains(static fn (AgentRun $run): bool => $run->isAutomatic())) {
            $next = $this->nextWork($task, $steps, $questions);

            if ($next !== null) {
                if ($this->budgetSpent($task)) {
                    $task->transitionTo(TaskStatus::Stalled, EventActor::System, "This task used all {$this->settings->runBudget()} of its agent runs.");

                    return;
                }

                $active->push($this->dispatcher->schedule($task, $next));
            }
        }

        $task->transitionTo($this->derive($task, $steps, $questions, $active), EventActor::System);
    }

    /**
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, AgentRun>  $active
     * @return Collection<int, AgentRun>
     */
    private function refreshReadiness(Task $task, Collection $steps, Collection $active): Collection
    {
        foreach ($steps as $step) {
            $run = $active->first(static fn (AgentRun $run): bool => $run->step_id === $step->id);

            if ($step->status === StepStatus::Done || $run?->status === RunStatus::Running) {
                continue;
            }

            $blocked = $step->dependencies->contains(static fn (Step $dependency): bool => $dependency->status !== StepStatus::Done)
                || $step->requirements->contains(static fn (Question $question): bool => $question->status === QuestionStatus::Open);

            if ($blocked && $run !== null) {
                $run->cancel(CarbonImmutable::now());
                TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'queued', 'reason' => 'requirements_changed'], EventActor::System, $run);
                $active = $active->reject(static fn (AgentRun $candidate): bool => $candidate->is($run))->values();
            }

            $step->transitionTo($blocked ? StepStatus::Blocked : StepStatus::Ready, EventActor::System);
        }

        return $active;
    }

    /**
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Question>  $questions
     */
    private function nextWork(Task $task, Collection $steps, Collection $questions): ?NextRun
    {
        if ($task->interpreted_at === null) {
            return NextRun::interpret();
        }

        if ($task->planned_at === null) {
            return NextRun::plan();
        }

        $ready = $steps->first(static fn (Step $step): bool => $step->status === StepStatus::Ready);

        if ($ready !== null) {
            return NextRun::generate($ready);
        }

        if ($steps->contains(static fn (Step $step): bool => $step->status !== StepStatus::Done)) {
            return null;
        }

        if ($questions->contains(static fn (Question $question): bool => $question->kind === QuestionKind::Essential && $question->status === QuestionStatus::Open)) {
            return null;
        }

        foreach ($steps as $step) {
            $version = $step->artifact?->currentVersion;

            if ($step->artifact !== null && $version !== null && $this->lacksEssentialAnswer($version, $questions)) {
                return NextRun::finalise($step->artifact, $version->id);
            }
        }

        return $task->reviewed_at === null ? NextRun::review() : null;
    }

    /** @param Collection<int, Question> $questions */
    public function lacksEssentialAnswer(ArtifactVersion $version, Collection $questions): bool
    {
        if (! $version->source->isProducedByAgent()) {
            return false;
        }

        foreach ($questions as $question) {
            if ($question->kind !== QuestionKind::Essential || $question->status !== QuestionStatus::Answered) {
                continue;
            }

            $seenQuestions = $version->inputs['questions'] ?? [];
            $seen = is_array($seenQuestions) ? ($seenQuestions[$question->id] ?? null) : null;

            if (! is_array($seen) || ($seen['status'] ?? null) !== QuestionStatus::Answered->value || ($seen['revision'] ?? 0) < $question->answer_revision) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Question>  $questions
     * @param  Collection<int, AgentRun>  $active
     */
    private function derive(Task $task, Collection $steps, Collection $questions, Collection $active): TaskStatus
    {
        if ($active->isNotEmpty()) {
            return TaskStatus::Ripening;
        }

        $complete = $steps->isNotEmpty() && $steps->every(static fn (Step $step): bool => $step->status === StepStatus::Done);
        $essentialOpen = $questions->contains(static fn (Question $question): bool => $question->kind === QuestionKind::Essential && $question->status === QuestionStatus::Open);
        $reviewed = $task->reviewed_at !== null && $task->artifacts()->where('kind', ArtifactKind::Review)->exists();

        return $complete && $reviewed && ! $essentialOpen ? TaskStatus::Ripe : TaskStatus::Waiting;
    }

    private function budgetSpent(Task $task): bool
    {
        return AgentRun::query()->where('task_id', $task->id)->where('status', '!=', RunStatus::Cancelled)->count() >= $this->settings->runBudget();
    }
}
