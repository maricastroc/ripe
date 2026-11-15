<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventActor;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Enums\TaskEventType;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\GuardsStatusTransitions;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable(['task_id', 'position', 'text', 'rationale', 'kind'])]
class Question extends Model
{
    use BelongsToTask;
    use GuardsStatusTransitions;

    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => 'open',
        'rationale' => '',
        'answer_revision' => 0,
    ];

    /** @return BelongsToMany<Step, $this> */
    public function requiredBy(): BelongsToMany
    {
        return $this->belongsToMany(Step::class, 'step_requirements');
    }

    public function answer(string $answer, EventActor $actor = EventActor::Visitor): void
    {
        Transactions::require('Answering a question');

        $task = $this->lockOwningTask();

        $clean = trim($answer);

        if ($clean === '') {
            throw new InvalidArgumentException('An answer cannot be empty.');
        }

        if ($this->status === QuestionStatus::Answered && $this->answer === $clean) {
            return;
        }

        if ($this->status !== QuestionStatus::Answered) {
            $this->status->assertCanTransitionTo(QuestionStatus::Answered);
        }

        $this->status = QuestionStatus::Answered;
        $this->answer = $clean;
        $this->answer_revision++;
        $this->resolved_at = CarbonImmutable::now();
        $this->save();

        TaskEvent::record($task, TaskEventType::QuestionAnswered, [
            'question_id' => $this->id,
            'revision' => $this->answer_revision,
        ], $actor);
    }

    public function skip(EventActor $actor = EventActor::Visitor): void
    {
        $this->resolveAs(QuestionStatus::Skipped, TaskEventType::QuestionSkipped, $actor);
    }

    public function reopen(EventActor $actor = EventActor::Visitor): void
    {
        $this->resolveAs(QuestionStatus::Open, TaskEventType::QuestionReopened, $actor);
    }

    private function resolveAs(QuestionStatus $to, TaskEventType $type, EventActor $actor): void
    {
        Transactions::require('Changing a question');

        $task = $this->lockOwningTask();

        if ($this->status === $to) {
            return;
        }

        $this->status->assertCanTransitionTo($to);

        $this->status = $to;
        $this->answer = null;
        $this->resolved_at = $to === QuestionStatus::Open ? null : CarbonImmutable::now();
        $this->save();

        TaskEvent::record($task, $type, ['question_id' => $this->id], $actor);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => QuestionKind::class,
            'status' => QuestionStatus::class,
            'answer_revision' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
