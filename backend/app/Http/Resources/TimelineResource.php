<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AttemptOutcome;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Task $resource */
final class TimelineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $runs = $this->resource->runs;
        $attempts = $runs->flatMap(static fn (AgentRun $run) => $run->attempts->each(static fn (RunAttempt $attempt): RunAttempt => $attempt->setRelation('run', $run)));

        return [
            'taskId' => $this->resource->id,
            'runs' => TimelineRunResource::collection($runs),
            'totals' => [
                'attempts' => $attempts->count(),
                'savedWork' => $attempts->filter(static fn (RunAttempt $attempt): bool => $attempt->outcome === AttemptOutcome::Succeeded)->count(),
                'workingMs' => (int) $attempts->sum(static fn (RunAttempt $attempt): int => $attempt->duration_ms ?? 0),
                'waitingMs' => (int) $attempts->sum(static fn (RunAttempt $attempt): int => TimelineAttemptResource::waitedMs($attempt)),
                'promptTokens' => (int) $attempts->sum(static fn (RunAttempt $attempt): int => $attempt->prompt_tokens ?? 0),
                'completionTokens' => (int) $attempts->sum(static fn (RunAttempt $attempt): int => $attempt->completion_tokens ?? 0),
            ],
        ];
    }
}
