<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Step;
use App\Models\Task;
use App\Retention\Retention;
use App\Snapshots\TaskState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Task $resource */
class TaskSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->summary(TaskState::of($this->resource));
    }

    /** @return array<string, mixed> */
    protected function summary(TaskState $state): array
    {
        $task = $this->resource;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'prompt' => $task->prompt,
            'status' => $task->status,
            'fruit' => $task->fruit,
            'isExample' => $task->is_example,
            'version' => $task->version,
            'returnAt' => $task->return_at?->toIso8601ZuluString('microsecond'),
            'createdAt' => $task->created_at?->toIso8601ZuluString('microsecond'),
            'expiresAt' => Retention::expiresAt($task)?->toIso8601ZuluString('microsecond'),
            'example' => $task->exampleRecord === null ? null : [
                'driver' => $task->exampleRecord->driver,
                'model' => $task->exampleRecord->model,
                'generatedAt' => $task->exampleRecord->generated_at?->toIso8601ZuluString(),
            ],
            'ripeAt' => $task->ripe_at?->toIso8601ZuluString('microsecond'),
            'harvestedAt' => $task->harvested_at?->toIso8601ZuluString('microsecond'),
            'stallReason' => $task->stall_reason,
            'growth' => [
                'stage' => $state->growth->stage,
                'maturity' => round($state->growth->maturity, 4),
                'stepsDone' => $state->growth->stepsDone,
                'stepsTotal' => $state->growth->stepsTotal,
            ],
            'leaves' => $task->steps->map(static fn (Step $step): array => [
                'id' => $step->id,
                'title' => $step->title,
                'phase' => $state->phaseOf($step),
                'artifactId' => $step->artifact?->id,
            ])->values()->all(),
            'openQuestions' => $state->openQuestions,
            'essentialOpen' => $state->essentialOpen,
            'activity' => $state->activeRun === null ? null : new ActivityResource($state->activeRun),
        ];
    }
}
