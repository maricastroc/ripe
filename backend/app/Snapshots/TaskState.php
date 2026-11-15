<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\StepPhase;
use App\Enums\StepStatus;
use App\Enums\WaitingFor;
use App\Growth\Growth;
use App\Growth\GrowthCalculator;
use App\Models\AgentRun;
use App\Models\Question;
use App\Models\Step;
use App\Models\Task;

final readonly class TaskState
{
    /** @param array<string, StepPhase> $phases */
    private function __construct(
        public Growth $growth,
        public ?AgentRun $activeRun,
        private array $phases,
        public int $openQuestions,
        public int $essentialOpen,
    ) {}

    public static function of(Task $task): self
    {
        $steps = $task->steps;
        $questions = $task->questions;
        $active = $task->activeRuns->sortBy(static fn (AgentRun $run): int => $run->status === RunStatus::Running ? 0 : 1)->first();
        $writing = $active !== null && $active->status === RunStatus::Running && $active->kind === RunKind::Generate ? $active->step_id : null;
        $phases = [];

        foreach ($steps as $step) {
            $phases[$step->id] = match (true) {
                $step->status === StepStatus::Done => StepPhase::Done,
                $step->id === $writing => StepPhase::Working,
                $step->status === StepStatus::Ready => StepPhase::Ready,
                default => StepPhase::Blocked,
            };
        }

        $open = $questions->filter(static fn (Question $question): bool => $question->status === QuestionStatus::Open);

        return new self(
            growth: (new GrowthCalculator)->calculate(
                interpreted: $task->interpreted_at !== null,
                planned: $task->planned_at !== null,
                reviewed: $task->reviewed_at !== null,
                stepsDone: $steps->filter(static fn (Step $step): bool => $step->status === StepStatus::Done)->count(),
                stepsTotal: $steps->count(),
            ),
            activeRun: $active,
            phases: $phases,
            openQuestions: $open->count(),
            essentialOpen: $open->filter(static fn (Question $question): bool => $question->kind === QuestionKind::Essential)->count(),
        );
    }

    public function phaseOf(Step $step): StepPhase
    {
        return $this->phases[$step->id] ?? StepPhase::Blocked;
    }

    public static function waitingFor(Step $step): ?WaitingFor
    {
        if ($step->status !== StepStatus::Blocked) {
            return null;
        }

        if ($step->dependencies->contains(static fn (Step $dependency): bool => $dependency->status !== StepStatus::Done)) {
            return WaitingFor::Step;
        }

        return WaitingFor::Answer;
    }
}
