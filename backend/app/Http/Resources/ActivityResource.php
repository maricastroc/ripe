<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Observability\PublicError;
use App\Orchestration\Output\LiveText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property AgentRun $resource */
final class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        $latest = $run->latestAttempt;
        $live = LiveText::of($run);

        return [
            'runId' => $run->id,
            'kind' => $run->kind,
            'running' => $run->status === RunStatus::Running,
            'subject' => $run->step->title ?? $run->artifact?->title,
            'stepId' => $run->step_id,
            'artifactId' => $run->artifact_id,
            'attempts' => $run->attempt_count,
            'maxAttempts' => $run->max_attempts,
            'startedAt' => $run->started_at?->toIso8601ZuluString('microsecond'),
            'availableAt' => $run->available_at?->toIso8601ZuluString('microsecond'),
            'deferredReason' => $run->deferred_reason,
            /** @var string|null */
            'lastError' => $latest !== null && $latest->finished_at !== null ? PublicError::of($latest->outcome, $latest->error_class) : null,
            'live' => $live === null ? null : [
                'attemptId' => $live->attemptId,
                'attempt' => $live->attemptNumber,
                'text' => $live->text,
                'length' => $live->length(),
            ],
        ];
    }
}
