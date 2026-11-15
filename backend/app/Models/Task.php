<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Deliverable;
use App\Enums\EventActor;
use App\Enums\Fruit;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Concerns\GuardsStatusTransitions;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property list<string> $context
 * @property list<string> $assumptions
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable([
    'visitor_id', 'is_example', 'prompt', 'title', 'language', 'deliverable', 'fruit', 'outcome',
    'context', 'assumptions', 'return_at', 'deferred_at', 'interpreted_at', 'planned_at', 'reviewed_at',
])]
class Task extends Model
{
    use GuardsStatusTransitions;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => 'dormant',
        'is_example' => false,
        'context' => '[]',
        'assumptions' => '[]',
        'version' => 0,
    ];

    /** @return BelongsTo<Visitor, $this> */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /** @return HasMany<Step, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(Step::class)->orderBy('position');
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /** @return HasMany<Artifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasMany<TaskEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderBy('sequence');
    }

    public function transitionTo(TaskStatus $to, EventActor $actor, ?string $reason = null): void
    {
        Transactions::require('A task transition');

        $from = $this->status;

        if ($from === $to) {
            return;
        }

        $from->assertCanTransitionTo($to);

        if ($to === TaskStatus::Stalled && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('A stalled task needs a reason.');
        }

        if ($to === TaskStatus::Ripe && $this->reviewed_at === null) {
            throw new DomainException('A task can only ripen after its review.');
        }

        $now = CarbonImmutable::now();

        $this->status = $to;
        $this->stall_reason = $to === TaskStatus::Stalled ? $reason : null;
        $this->harvested_at = $to === TaskStatus::Harvested ? $now : null;

        if ($to === TaskStatus::Ripe) {
            $this->ripe_at ??= $now;
        }

        $this->save();

        $payload = ['from' => $from->value, 'to' => $to->value];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        TaskEvent::record($this, TaskEventType::TaskStatusChanged, $payload, $actor);
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'deliverable' => Deliverable::class,
            'fruit' => Fruit::class,
            'is_example' => 'boolean',
            'context' => 'array',
            'assumptions' => 'array',
            'version' => 'integer',
            'return_at' => 'immutable_datetime',
            'deferred_at' => 'immutable_datetime',
            'interpreted_at' => 'immutable_datetime',
            'planned_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'ripe_at' => 'immutable_datetime',
            'harvested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
