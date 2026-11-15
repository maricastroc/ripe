<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Agent\AgentRequests;
use App\Agent\Brief;
use App\Agent\Prompts\CurrentStep;
use App\Agent\Prompts\Piece;
use App\Agent\Prompts\PlanLine;
use App\Agent\Prompts\Prompt;
use App\Agent\Prompts\PromptBook;
use App\Agent\SafetyIdentifier;
use App\Enums\RunKind;
use App\Enums\StepStatus;
use App\Models\AgentRun;
use App\Models\Step;
use App\Models\Task;
use LogicException;

final class RunRequests
{
    public function __construct(
        private readonly PromptBook $book,
        private readonly AgentRequests $requests,
    ) {}

    public function prepare(AgentRun $run): PreparedCall
    {
        $task = $run->owningTask();
        $brief = Brief::fromTask($task);
        $steps = $task->steps()->with('artifact.currentVersion')->get();
        $questions = [];

        foreach ($task->questions()->get() as $question) {
            $questions[$question->id] = ['status' => $question->status->value, 'revision' => $question->answer_revision];
        }

        [$prompt, $artifacts] = match ($run->kind) {
            RunKind::Interpret => [$this->book->interpret($task->prompt), []],
            RunKind::Plan => [$this->book->plan($brief), []],
            RunKind::Generate => $this->generation($run, $brief, array_values($steps->all())),
            RunKind::Review => $this->review($brief, array_values($steps->all())),
            RunKind::Revise => $this->revision($run, $brief),
        };

        return new PreparedCall(
            request: $this->requests->build($run->kind, $prompt, $this->safetyIdentifier($task)),
            schema: $prompt->schema,
            inputs: ['artifacts' => $artifacts, 'questions' => $questions],
        );
    }

    /**
     * @param  list<Step>  $steps
     * @return array{0: Prompt, 1: array<string, int>}
     */
    private function generation(AgentRun $run, Brief $brief, array $steps): array
    {
        $current = null;
        $prior = [];
        $used = [];

        foreach ($steps as $step) {
            if ($step->id === $run->step_id) {
                $current = $step;

                break;
            }

            $version = $step->artifact?->currentVersion;

            if ($step->artifact !== null && $version !== null) {
                $prior[] = new Piece($step->title, $version->content);
                $used[$step->artifact->id] = $version->number;
            }
        }

        if ($current === null) {
            throw new LogicException('The step of this run no longer exists.');
        }

        $plan = array_map(static fn (Step $step): PlanLine => new PlanLine($step->position + 1, $step->title, $step->status === StepStatus::Done), $steps);

        return [
            $this->book->generate($brief, $plan, new CurrentStep($current->position + 1, $current->title, $current->description, $current->output_format), $prior),
            $used,
        ];
    }

    /**
     * @param  list<Step>  $steps
     * @return array{0: Prompt, 1: array<string, int>}
     */
    private function review(Brief $brief, array $steps): array
    {
        $pieces = [];
        $used = [];

        foreach ($steps as $step) {
            $version = $step->artifact?->currentVersion;

            if ($step->artifact !== null && $version !== null) {
                $pieces[] = new Piece($step->title, $version->content);
                $used[$step->artifact->id] = $version->number;
            }
        }

        return [$this->book->review($brief, $pieces), $used];
    }

    /** @return array{0: Prompt, 1: array<string, int>} */
    private function revision(AgentRun $run, Brief $brief): array
    {
        $artifact = $run->artifact()->firstOrFail();
        $base = $run->baseVersion()->firstOrFail();

        return [
            $this->book->revise($brief, $artifact->title, $base->content, $run->instruction),
            [$artifact->id => $base->number],
        ];
    }

    private function safetyIdentifier(Task $task): ?string
    {
        return $task->visitor_id === null ? null : SafetyIdentifier::forVisitor($task->visitor_id);
    }
}
