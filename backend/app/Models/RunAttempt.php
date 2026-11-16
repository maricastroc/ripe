<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptOutcome;
use App\Enums\ChargeBasis;
use App\Observability\AgentLog;
use App\Orchestration\AttemptReport;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\Quota\QuotaGate;
use Carbon\CarbonImmutable;
use Database\Factories\RunAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DateFormat('Y-m-d H:i:s.uP')]
#[WithoutTimestamps]
#[Fillable([
    'run_id', 'number', 'lease_token', 'worker', 'queue_job_id', 'started_at', 'last_heartbeat_at',
    'finished_at', 'outcome', 'outcome_detail', 'error_class', 'error_message', 'driver', 'model',
    'provider_request_id', 'provider_response_id', 'prompt_tokens', 'cached_prompt_tokens', 'cache_write_tokens',
    'completion_tokens', 'reasoning_tokens', 'usage_estimated', 'ttft_ms', 'duration_ms', 'cost_microusd', 'reserved_microusd',
    'budget_day', 'budget_visitor_id',
])]
class RunAttempt extends Model
{
    /** @use HasFactory<RunAttemptFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    public function finish(AttemptOutcome $outcome, CarbonImmutable $now, AttemptReport $report = new AttemptReport): bool
    {
        if ($this->finished_at !== null) {
            return false;
        }

        $usage = $report->usage;

        $this->outcome = $outcome;
        $this->finished_at = $now;
        $this->outcome_detail = $report->detail === null ? null : mb_substr($report->detail, 0, 32);
        $this->error_class = $report->errorClass;
        $this->error_message = $report->errorMessage;
        $this->model = $report->model ?? $this->model;
        $this->provider_request_id = $report->requestId === null ? null : mb_substr($report->requestId, 0, 128);
        $this->provider_response_id = $report->responseId === null ? null : mb_substr($report->responseId, 0, 128);
        $this->prompt_tokens = $usage?->inputTokens;
        $this->cached_prompt_tokens = $usage?->cachedInputTokens;
        $this->cache_write_tokens = $usage?->cacheWriteTokens;
        $this->completion_tokens = $usage?->outputTokens;
        $this->reasoning_tokens = $usage?->reasoningTokens;
        $this->usage_estimated = $usage !== null && $usage->estimated;
        $this->cost_microusd = $report->costMicrodollars;
        $this->ttft_ms = $report->timeToFirstTokenMs;
        $this->duration_ms = $report->durationMs ?? ($this->started_at === null ? null : max(0, (int) round($this->started_at->diffInMilliseconds($now))));
        $this->save();

        app(InferenceBudget::class)->settle($this, $report, $now);
        app(QuotaGate::class)->settle($this, $report, $now);
        app(AgentLog::class)->attemptFinished($this);

        return true;
    }

    public function recordLateUsage(AttemptReport $report): void
    {
        if ($report->usage === null || $this->prompt_tokens !== null) {
            return;
        }

        $this->prompt_tokens = $report->usage->inputTokens;
        $this->cached_prompt_tokens = $report->usage->cachedInputTokens;
        $this->cache_write_tokens = $report->usage->cacheWriteTokens;
        $this->completion_tokens = $report->usage->outputTokens;
        $this->reasoning_tokens = $report->usage->reasoningTokens;
        $this->usage_estimated = $report->usage->estimated;
        $this->cost_microusd ??= $report->costMicrodollars;
        $this->save();
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'outcome' => AttemptOutcome::class,
            'started_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'prompt_tokens' => 'integer',
            'cached_prompt_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'usage_estimated' => 'boolean',
            'ttft_ms' => 'integer',
            'duration_ms' => 'integer',
            'cost_microusd' => 'integer',
            'reserved_microusd' => 'integer',
            'settled_at' => 'immutable_datetime',
            'charged_microusd' => 'integer',
            'charge_basis' => ChargeBasis::class,
        ];
    }
}
