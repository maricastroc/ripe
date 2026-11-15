<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventActor;
use App\Enums\OutputFormat;
use App\Enums\QuestionKind;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\GuardsStatusTransitions;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Database\Factories\StepFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable(['task_id', 'position', 'title', 'description', 'output_format', 'status'])]
class Step extends Model
{
    use BelongsToTask;
    use GuardsStatusTransitions;

    /** @use HasFactory<StepFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => 'ready',
    ];

    /** @return BelongsToMany<Step, $this> */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Step::class, 'step_dependencies', 'step_id', 'depends_on_step_id');
    }

    /** @return BelongsToMany<Step, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Step::class, 'step_dependencies', 'depends_on_step_id', 'step_id');
    }

    /** @return BelongsToMany<Question, $this> */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'step_requirements');
    }

    /** @return HasOne<Artifact, $this> */
    public function artifact(): HasOne
    {
        return $this->hasOne(Artifact::class);
    }

    public function dependOn(Step $earlier): void
    {
        if ($earlier->task_id !== $this->task_id) {
            throw new DomainException('A step can only depend on a step of the same task.');
        }

        if ($earlier->position >= $this->position) {
            throw new DomainException('A step can only build on an earlier step.');
        }

        $this->dependencies()->syncWithoutDetaching([$earlier->id => ['task_id' => $this->task_id]]);
    }

    public function requireAnswer(Question $question): void
    {
        if ($question->task_id !== $this->task_id) {
            throw new DomainException('A step can only require a question of the same task.');
        }

        if ($question->kind !== QuestionKind::Essential) {
            throw new DomainException('Only essential questions can hold a step back.');
        }

        $this->requirements()->syncWithoutDetaching([$question->id => [
            'task_id' => $this->task_id,
            'question_kind' => $question->kind->value,
        ]]);
    }

    public function transitionTo(StepStatus $to, EventActor $actor): void
    {
        Transactions::require('A step transition');

        $task = $this->lockOwningTask();

        if ($this->status === $to) {
            return;
        }

        $this->status->assertCanTransitionTo($to);

        $this->status = $to;
        $this->completed_at = $to === StepStatus::Done ? CarbonImmutable::now() : null;
        $this->save();

        $type = match ($to) {
            StepStatus::Ready => TaskEventType::StepReady,
            StepStatus::Blocked => TaskEventType::StepBlocked,
            StepStatus::Done => TaskEventType::StepCompleted,
        };

        TaskEvent::record($task, $type, [
            'step_id' => $this->id,
            'position' => $this->position,
        ], $actor);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'output_format' => OutputFormat::class,
            'status' => StepStatus::class,
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
