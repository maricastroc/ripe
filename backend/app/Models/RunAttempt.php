<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptOutcome;
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
    'prompt_tokens', 'completion_tokens', 'ttft_ms', 'duration_ms', 'cost_microusd', 'reserved_microusd',
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

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'outcome' => AttemptOutcome::class,
            'started_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'ttft_ms' => 'integer',
            'duration_ms' => 'integer',
            'cost_microusd' => 'integer',
            'reserved_microusd' => 'integer',
        ];
    }
}
