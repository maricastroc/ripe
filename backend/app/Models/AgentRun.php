<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunOrigin;
use App\Enums\RunStatus;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\GuardsStatusTransitions;
use Carbon\CarbonImmutable;
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
    'task_id', 'kind', 'origin', 'step_id', 'artifact_id', 'base_version_id', 'instruction',
    'available_at', 'dispatched_at', 'deferred_reason', 'max_attempts',
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
        'origin' => 'orchestrator',
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

    public function holdsLease(string $token, CarbonImmutable $now): bool
    {
        return $this->status === RunStatus::Running
            && $this->lease_token === $token
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->greaterThan($now)
            && $this->cancel_requested_at === null;
    }

    public function isAutomatic(): bool
    {
        return $this->origin === RunOrigin::Orchestrator;
    }

    public function startLease(string $token, CarbonImmutable $now, CarbonImmutable $expiresAt): void
    {
        $this->status = RunStatus::Running;
        $this->lease_token = $token;
        $this->lease_expires_at = $expiresAt;
        $this->last_heartbeat_at = $now;
        $this->started_at ??= $now;
        $this->deferred_reason = null;
        $this->save();
    }

    public function requeue(CarbonImmutable $availableAt, ?RunDeferral $reason, bool $consumeAttempt = false, bool $interrupted = false): void
    {
        $this->status = RunStatus::Queued;
        $this->releaseLease();
        $this->available_at = $availableAt;
        $this->deferred_reason = $reason;

        if ($consumeAttempt) {
            $this->attempt_count++;
        }

        if ($interrupted) {
            $this->interruption_count++;
        }

        $this->save();
    }

    public function defer(CarbonImmutable $availableAt, RunDeferral $reason): void
    {
        $this->available_at = $availableAt;
        $this->deferred_reason = $reason;
        $this->save();
    }

    public function succeed(CarbonImmutable $now): void
    {
        $this->status = RunStatus::Succeeded;
        $this->finish($now);
    }

    public function fail(string $reason, CarbonImmutable $now, bool $consumeAttempt = false, bool $interrupted = false): void
    {
        if ($consumeAttempt) {
            $this->attempt_count = min($this->attempt_count + 1, $this->max_attempts);
        }

        if ($interrupted) {
            $this->interruption_count++;
        }

        $this->status = RunStatus::Failed;
        $this->failure_reason = mb_substr($reason, 0, 2000);
        $this->finish($now);
    }

    public function cancel(CarbonImmutable $now): void
    {
        $this->status = RunStatus::Cancelled;
        $this->finish($now);
    }

    public function requestCancellation(CarbonImmutable $now): void
    {
        $this->cancel_requested_at ??= $now;
        $this->save();
    }

    public function wasDispatched(CarbonImmutable $now): void
    {
        $this->dispatched_at = $now;
        $this->save();
    }

    private function finish(CarbonImmutable $now): void
    {
        $this->releaseLease();
        $this->deferred_reason = null;
        $this->finished_at = $now;
        $this->save();
    }

    private function releaseLease(): void
    {
        $this->lease_token = null;
        $this->lease_expires_at = null;
    }

    protected function casts(): array
    {
        return [
            'kind' => RunKind::class,
            'origin' => RunOrigin::class,
            'status' => RunStatus::class,
            'deferred_reason' => RunDeferral::class,
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'interruption_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
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
