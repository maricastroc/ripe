<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AttemptOutcome;
use App\Models\RunAttempt;
use App\Observability\PublicError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property RunAttempt $resource */
final class TimelineAttemptResource extends JsonResource
{
    public static function waitedMs(RunAttempt $attempt): int
    {
        $run = $attempt->run;

        if ($run === null || $attempt->started_at === null) {
            return 0;
        }

        $previous = $run->attempts->first(static fn (RunAttempt $earlier): bool => $earlier->number === $attempt->number - 1);
        $since = $previous instanceof RunAttempt && $previous->finished_at !== null ? $previous->finished_at : $run->created_at;

        return $since === null ? 0 : max(0, (int) round($since->diffInMilliseconds($attempt->started_at)));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attempt = $this->resource;

        return [
            'id' => $attempt->id,
            'number' => $attempt->number,
            'outcome' => $attempt->outcome,
            'savedWork' => $attempt->outcome === AttemptOutcome::Succeeded,
            'startedAt' => $attempt->started_at?->toIso8601ZuluString('microsecond'),
            'finishedAt' => $attempt->finished_at?->toIso8601ZuluString('microsecond'),
            'waitedMs' => self::waitedMs($attempt),
            'durationMs' => $attempt->duration_ms,
            'firstTokenMs' => $attempt->ttft_ms,
            'promptTokens' => $attempt->prompt_tokens,
            'completionTokens' => $attempt->completion_tokens,
            'usageEstimated' => $attempt->usage_estimated,
            'driver' => $attempt->driver,
            'model' => $attempt->model,
            'costMicrousd' => $attempt->cost_microusd,
            /** @var string|null */
            'error' => PublicError::of($attempt->outcome, $attempt->error_class),
        ];
    }
}
