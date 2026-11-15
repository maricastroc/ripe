<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\Concerns\Immutable;
use App\Realtime\TaskChanges;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** @property array<string, mixed> $payload */
#[DateFormat('Y-m-d H:i:s.uP')]
#[WithoutTimestamps]
#[Fillable(['task_id', 'sequence', 'type', 'actor', 'run_id', 'attempt_id', 'payload', 'occurred_at'])]
class TaskEvent extends Model
{
    use Immutable;

    /** @param array<string, mixed> $payload */
    public static function record(
        Task $task,
        TaskEventType $type,
        array $payload,
        EventActor $actor,
        ?AgentRun $run = null,
        ?RunAttempt $attempt = null,
    ): self {
        Transactions::require('Appending a task event');

        $row = DB::selectOne(
            'update tasks set version = version + 1 where id = ? returning version',
            [$task->id],
            false,
        );

        if (! is_object($row) || ! property_exists($row, 'version') || ! is_numeric($row->version)) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
        }

        $sequence = (int) $row->version;
        $task->forceFill(['version' => $sequence])->syncOriginalAttribute('version');
        app(TaskChanges::class)->changed($task);

        return self::query()->create([
            'task_id' => $task->id,
            'sequence' => $sequence,
            'type' => $type,
            'actor' => $actor,
            'run_id' => $run?->id,
            'attempt_id' => $attempt?->id,
            'payload' => $payload,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return BelongsTo<RunAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(RunAttempt::class, 'attempt_id');
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'type' => TaskEventType::class,
            'actor' => EventActor::class,
            'payload' => JsonObject::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
