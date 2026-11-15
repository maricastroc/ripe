<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\GuardsStatusTransitions;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable([
    'task_id', 'kind', 'step_id', 'artifact_id', 'base_version_id', 'instruction',
    'available_at', 'deferred_reason', 'max_attempts',
])]
class AgentRun extends Model
{
    use BelongsToTask;
    use GuardsStatusTransitions;

    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => 'queued',
        'attempt_count' => 0,
        'interruption_count' => 0,
    ];

    /** @return BelongsTo<Step, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return BelongsTo<ArtifactVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(ArtifactVersion::class, 'base_version_id');
    }

    /** @return HasMany<RunAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(RunAttempt::class, 'run_id')->orderBy('number');
    }

    protected function casts(): array
    {
        return [
            'kind' => RunKind::class,
            'status' => RunStatus::class,
            'deferred_reason' => RunDeferral::class,
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'interruption_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
