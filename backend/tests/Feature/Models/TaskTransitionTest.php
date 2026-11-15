<?php

declare(strict_types=1);

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\TransactionRequired;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

it('records every status change in the journal', function (): void {
    $task = Task::factory()->create();

    DB::transaction(fn () => $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor));

    $event = $task->events()->sole();

    expect($task->fresh()?->status)->toBe(TaskStatus::Ripening)
        ->and($event->type)->toBe(TaskEventType::TaskStatusChanged)
        ->and($event->actor)->toBe(EventActor::Visitor)
        ->and($event->payload)->toEqual(['from' => 'dormant', 'to' => 'ripening']);
});

it('rejects a forbidden transition and changes nothing', function (): void {
    $task = Task::factory()->create();

    expect(fn () => DB::transaction(fn () => $task->transitionTo(TaskStatus::Waiting, EventActor::System)))
        ->toThrow(InvalidTransition::class);

    expect($task->fresh()?->status)->toBe(TaskStatus::Dormant)
        ->and($task->events()->count())->toBe(0);
});

it('guards status changes made directly through Eloquent', function (): void {
    $task = Task::factory()->create();
    $task->status = TaskStatus::Ripe;

    expect(fn () => $task->save())->toThrow(InvalidTransition::class)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Dormant);
});

it('needs a transaction', function (): void {
    $task = Task::factory()->create();

    expect(fn () => $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor))
        ->toThrow(TransactionRequired::class)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Dormant);
});

it('ripens only after the review and remembers when it first did', function (): void {
    $planned = Task::factory()->planned()->create();

    expect(fn () => DB::transaction(fn () => $planned->transitionTo(TaskStatus::Ripe, EventActor::Agent)))
        ->toThrow(DomainException::class);

    $task = Task::factory()->reviewed()->create();
    DB::transaction(fn () => $task->transitionTo(TaskStatus::Ripe, EventActor::Agent));
    $firstRipe = $task->fresh()?->ripe_at;

    $this->travel(1)->hour();
    DB::transaction(function () use ($task): void {
        $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor);
        $task->transitionTo(TaskStatus::Ripe, EventActor::Agent);
    });

    expect($firstRipe)->not->toBeNull()
        ->and($task->fresh()?->ripe_at?->equalTo($firstRipe))->toBeTrue();
});
