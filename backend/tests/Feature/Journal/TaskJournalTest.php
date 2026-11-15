<?php

declare(strict_types=1);

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Exceptions\ImmutableRecord;
use App\Exceptions\TransactionRequired;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

it('numbers events from one without gaps and keeps the task version in step', function (): void {
    $task = Task::factory()->create();

    DB::transaction(function () use ($task): void {
        TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor);
        TaskEvent::record($task, TaskEventType::TaskDeferred, ['return_at' => null], EventActor::Visitor);
        TaskEvent::record($task, TaskEventType::RunScheduled, [], EventActor::System);
    });

    expect($task->events()->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($task->version)->toBe(3)
        ->and($task->fresh()?->version)->toBe(3)
        ->and($task->isDirty('version'))->toBeFalse();
});

it('stores the type, actor and payload of each event', function (): void {
    $task = Task::factory()->create();

    $event = DB::transaction(fn (): TaskEvent => TaskEvent::record($task, TaskEventType::TaskRenamed, ['title' => 'New'], EventActor::Visitor));

    expect($event->fresh())
        ->type->toBe(TaskEventType::TaskRenamed)
        ->actor->toBe(EventActor::Visitor)
        ->payload->toBe(['title' => 'New']);
});

it('refuses to record outside a transaction', function (): void {
    $task = Task::factory()->create();

    expect(fn () => TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor))
        ->toThrow(TransactionRequired::class);

    expect(TaskEvent::query()->count())->toBe(0)
        ->and($task->fresh()?->version)->toBe(0);
});

it('leaves no gap when a transaction rolls back', function (): void {
    $task = Task::factory()->create();

    try {
        DB::transaction(function () use ($task): void {
            TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor);

            throw new RuntimeException('The surrounding work failed.');
        });
    } catch (RuntimeException) {
    }

    $fresh = $task->fresh() ?? throw new RuntimeException('The task disappeared.');
    DB::transaction(fn () => TaskEvent::record($fresh, TaskEventType::TaskPlanted, [], EventActor::Visitor));

    expect($task->events()->pluck('sequence')->all())->toBe([1]);
});

it('fails when the task no longer exists', function (): void {
    $task = Task::factory()->create();
    DB::table('tasks')->where('id', $task->id)->delete();

    expect(fn () => DB::transaction(fn () => TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor)))
        ->toThrow(ModelNotFoundException::class);
});

it('never changes or removes a recorded event', function (): void {
    $task = Task::factory()->create();
    $event = DB::transaction(fn (): TaskEvent => TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor));

    expect(fn () => $event->update(['payload' => ['changed' => true]]))->toThrow(ImmutableRecord::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableRecord::class)
        ->and($event->fresh()?->payload)->toBe([]);
});
