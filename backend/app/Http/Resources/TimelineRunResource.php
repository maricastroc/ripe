<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AgentRun;
use App\Models\RunAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property AgentRun $resource */
final class TimelineRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'status' => $run->status,
            'subject' => $run->step->title ?? $run->artifact?->title,
            'stepId' => $run->step_id,
            'artifactId' => $run->artifact_id,
            'scheduledAt' => $run->created_at?->toIso8601ZuluString('microsecond'),
            'finishedAt' => $run->finished_at?->toIso8601ZuluString('microsecond'),
            'deferredReason' => $run->deferred_reason,
            'attempts' => TimelineAttemptResource::collection($run->attempts->each(static fn (RunAttempt $attempt): RunAttempt => $attempt->setRelation('run', $run))),
        ];
    }
}
