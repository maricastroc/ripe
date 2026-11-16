<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Agent\Limits;
use App\Agent\Results\Interpretation;
use App\Agent\Results\Plan;
use App\Agent\Results\Review;
use App\Agent\StructuredOutput;
use App\Agent\Text\Markdown;
use App\Enums\ArtifactKind;
use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\Inference\FinishReason;
use App\Enums\QuestionKind;
use App\Enums\RunKind;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Inference\ModelResponse;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use LogicException;

final class ResultCommitter
{
    public function __construct(private readonly StructuredOutput $structured) {}

    public function prepare(AgentRun $run, PreparedCall $call, ModelResponse $response): PreparedResult
    {
        if (in_array($run->kind, [RunKind::Generate, RunKind::Revise], true)) {
            $content = Markdown::unfence($response->text);

            if (Markdown::words($content) < Limits::MIN_PIECE_WORDS) {
                throw new InvalidModelOutput('The generated text was empty or too short.');
            }

            return new PreparedResult(new WrittenPiece($content, $response->finishReason === FinishReason::Length), $call->inputs);
        }

        $schema = $call->schema ?? throw new LogicException("A {$run->kind->value} run needs a schema.");
        $value = $this->structured->decode($response->text, $schema);

        if (! $value instanceof Interpretation && ! $value instanceof Plan && ! $value instanceof Review) {
            throw new LogicException('Unexpected structured result.');
        }

        return new PreparedResult($value, $call->inputs);
    }

    public function apply(Task $task, AgentRun $run, PreparedResult $result, ?string $model): void
    {
        Transactions::require('Applying a run result');

        $value = $result->value;

        match (true) {
            $value instanceof Interpretation => $this->interpretation($task, $run, $value),
            $value instanceof Plan => $this->plan($task, $run, $value),
            $value instanceof Review => $this->review($task, $run, $value, $result->inputs, $model),
            $run->kind === RunKind::Generate => $this->piece($run, $value, $result->inputs, $model),
            default => $this->revision($run, $value, $result->inputs, $model),
        };
    }

    private function interpretation(Task $task, AgentRun $run, Interpretation $interpretation): void
    {
        if ($task->interpreted_at !== null) {
            return;
        }

        $task->fill([
            'title' => $interpretation->title,
            'language' => $interpretation->language,
            'deliverable' => $interpretation->deliverable,
            'fruit' => $interpretation->deliverable->fruit(),
            'outcome' => $interpretation->outcome,
            'context' => $interpretation->context,
            'assumptions' => $interpretation->assumptions,
            'interpreted_at' => CarbonImmutable::now(),
        ])->save();

        foreach ($interpretation->questions as $position => $asked) {
            $question = Question::query()->create([
                'task_id' => $task->id,
                'position' => $position,
                'text' => $asked->question,
                'rationale' => $asked->why,
                'kind' => $asked->kind,
            ]);

            TaskEvent::record($task, TaskEventType::QuestionAsked, ['question_id' => $question->id, 'kind' => $asked->kind->value], EventActor::Agent, $run);
        }

        $essential = array_filter($interpretation->questions, static fn ($question): bool => $question->kind === QuestionKind::Essential);

        TaskEvent::record($task, TaskEventType::TaskInterpreted, [
            'deliverable' => $interpretation->deliverable->value,
            'language' => $interpretation->language,
            'questions' => count($interpretation->questions),
            'essential' => count($essential),
        ], EventActor::Agent, $run);
    }

    private function plan(Task $task, AgentRun $run, Plan $plan): void
    {
        if ($task->planned_at !== null) {
            return;
        }

        $questions = $task->questions()->get()->keyBy(static fn (Question $question): int => $question->position + 1);
        $created = [];
        $summary = [];

        foreach ($plan->steps as $planned) {
            $step = Step::query()->create([
                'task_id' => $task->id,
                'position' => $planned->number - 1,
                'title' => $planned->title,
                'description' => $planned->description,
                'output_format' => $planned->output,
                'status' => StepStatus::Ready,
            ]);

            foreach ($planned->buildsOn as $number) {
                $step->dependOn($created[$number]);
            }

            foreach ($planned->requires as $number) {
                $question = $questions->get($number);

                if ($question !== null) {
                    $step->requireAnswer($question);
                }
            }

            $created[$planned->number] = $step;
            $summary[] = ['step_id' => $step->id, 'title' => $step->title, 'builds_on' => $planned->buildsOn, 'requires' => $planned->requires];
        }

        $task->planned_at = CarbonImmutable::now();
        $task->save();

        TaskEvent::record($task, TaskEventType::TaskPlanned, ['steps' => $summary], EventActor::Agent, $run);
    }

    /** @param array<string, mixed> $inputs */
    private function piece(AgentRun $run, Interpretation|Plan|Review|WrittenPiece $value, array $inputs, ?string $model): void
    {
        if (! $value instanceof WrittenPiece) {
            throw new LogicException('A generation run must produce a written piece.');
        }

        $step = $run->step()->firstOrFail();

        if ($step->status === StepStatus::Done) {
            return;
        }

        $artifact = $step->artifact()->first() ?? Artifact::query()->create([
            'task_id' => $step->task_id,
            'step_id' => $step->id,
            'kind' => ArtifactKind::Step,
            'title' => $step->title,
        ]);

        $artifact->addVersion($value->content, ArtifactVersionSource::Generate, EventActor::Agent, $run, inputs: $inputs + ['truncated' => $value->truncated], model: $model);
        $step->transitionTo(StepStatus::Done, EventActor::Agent);
    }

    /** @param array<string, mixed> $inputs */
    private function revision(AgentRun $run, Interpretation|Plan|Review|WrittenPiece $value, array $inputs, ?string $model): void
    {
        if (! $value instanceof WrittenPiece) {
            throw new LogicException('A revision run must produce a written piece.');
        }

        $run->artifact()->firstOrFail()->addVersion(
            $value->content,
            ArtifactVersionSource::Revise,
            EventActor::Agent,
            $run,
            instruction: $run->instruction,
            inputs: $inputs + ['base_version_id' => $run->base_version_id, 'truncated' => $value->truncated],
            model: $model,
        );
    }

    /** @param array<string, mixed> $inputs */
    private function review(Task $task, AgentRun $run, Review $review, array $inputs, ?string $model): void
    {
        if ($task->reviewed_at !== null) {
            return;
        }

        $artifact = $task->artifacts()->where('kind', ArtifactKind::Review)->first() ?? Artifact::query()->create([
            'task_id' => $task->id,
            'kind' => ArtifactKind::Review,
            'title' => __('Agent review', [], $this->contentLocale($task)),
        ]);

        $artifact->addVersion($review->toMarkdown($this->contentLocale($task)), ArtifactVersionSource::Review, EventActor::Agent, $run, inputs: $inputs, model: $model);

        $task->reviewed_at = CarbonImmutable::now();
        $task->save();

        TaskEvent::record($task, TaskEventType::ReviewCompleted, ['artifact_id' => $artifact->id, 'verdict' => $review->verdict->value], EventActor::Agent, $run);
    }

    private function contentLocale(Task $task): string
    {
        return str_starts_with(strtolower($task->language ?? ''), 'pt') ? 'pt_BR' : 'en';
    }
}
