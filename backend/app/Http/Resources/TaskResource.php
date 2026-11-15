<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Agent\Text\Markdown;
use App\Enums\ArtifactVersionSource;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\Step;
use App\Models\Task;
use App\Orchestration\AgentSettings;
use App\Snapshots\FieldNote;
use App\Snapshots\FieldNotes;
use App\Snapshots\Staleness;
use App\Snapshots\TaskSnapshots;
use App\Snapshots\TaskState;
use Illuminate\Http\Request;

/** @property Task $resource */
final class TaskResource extends TaskSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $state = TaskState::of($task);
        $numbers = $task->artifacts->mapWithKeys(static fn (Artifact $artifact): array => [$artifact->id => $artifact->currentVersion->number ?? 0])->all();
        $notes = (new FieldNotes($task->questions, $task->steps, $task->artifacts))->of($task->events);

        return [
            ...$this->summary($state),
            'number' => app(TaskSnapshots::class)->number($task),
            'language' => $task->language,
            'deliverable' => $task->deliverable,
            'outcome' => $task->outcome,
            'context' => array_values(array_filter($task->context, is_string(...))),
            'assumptions' => array_values(array_filter($task->assumptions, is_string(...))),
            'steps' => $task->steps->map(static fn (Step $step): array => [
                'id' => $step->id,
                'position' => $step->position,
                'title' => $step->title,
                'description' => $step->description,
                'output' => $step->output_format,
                'phase' => $state->phaseOf($step),
                'waitingFor' => TaskState::waitingFor($step),
                'dependsOn' => $step->dependencies->pluck('id')->values()->all(),
                'requires' => $step->requirements->pluck('id')->values()->all(),
                'artifactId' => $step->artifact?->id,
            ])->values()->all(),
            'questions' => $task->questions->map(static fn (Question $question): array => [
                'id' => $question->id,
                'position' => $question->position,
                'text' => $question->text,
                'why' => $question->rationale,
                'kind' => $question->kind,
                'status' => $question->status,
                'answer' => $question->answer,
                'answerRevision' => $question->answer_revision,
            ])->values()->all(),
            'artifacts' => $task->artifacts->map(fn (Artifact $artifact): array => $this->artifact($artifact, $numbers))->values()->all(),
            'events' => array_map(static fn (FieldNote $note): array => [
                'id' => $note->sequence,
                'kind' => $note->kind,
                'message' => $note->message,
                'createdAt' => $note->at->toIso8601ZuluString('microsecond'),
            ], $notes),
            'runsUsed' => AgentRun::query()->where('task_id', $task->id)->where('status', '!=', RunStatus::Cancelled)->count(),
            'runBudget' => app(AgentSettings::class)->runBudget(),
        ];
    }

    /**
     * @param  array<string, int>  $numbers
     * @return array<string, mixed>
     */
    private function artifact(Artifact $artifact, array $numbers): array
    {
        $version = $artifact->currentVersion;
        $draft = $artifact->draft;
        $content = $version->content ?? '';

        return [
            'id' => $artifact->id,
            'stepId' => $artifact->step_id,
            'kind' => $artifact->kind,
            'title' => $artifact->title,
            'content' => $content,
            'gaps' => Markdown::gaps($content),
            'editedByUser' => in_array($version?->source, [ArtifactVersionSource::UserEdit, ArtifactVersionSource::Restore], true),
            'stale' => $version !== null && Staleness::of($version, $this->resource->questions, $numbers),
            'model' => $version?->model,
            'updatedAt' => $version?->created_at?->toIso8601ZuluString('microsecond'),
            'version' => $version === null ? null : [
                'id' => $version->id,
                'number' => $version->number,
                'source' => $version->source,
            ],
            'versions' => $artifact->versions->map(static fn (ArtifactVersion $entry): array => [
                'id' => $entry->id,
                'number' => $entry->number,
                'source' => $entry->source,
                'instruction' => $entry->instruction,
                'createdAt' => $entry->created_at?->toIso8601ZuluString('microsecond'),
            ])->values()->all(),
            'draft' => $draft === null ? null : [
                'content' => $draft->content,
                'baseVersionId' => $draft->base_version_id,
                'revision' => $draft->revision,
                'conflicting' => $draft->base_version_id !== $artifact->current_version_id,
                'updatedAt' => $draft->updated_at?->toIso8601ZuluString('microsecond'),
            ],
        ];
    }
}
