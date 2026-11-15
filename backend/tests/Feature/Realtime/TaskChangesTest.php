<?php

declare(strict_types=1);

use App\Actions\Tasks\RenameTask;
use App\Models\Task;
use App\Realtime\Signal;
use Illuminate\Support\Facades\DB;

it('signals each task once, after the commit, with the version it committed', function (): void {
    $broadcasts = recordBroadcasts();
    [$visitor, $token] = visitor();
    $task = apiTask($token);
    $channels = ["private-task.{$task->id}", "private-greenhouse.{$visitor->id}"];

    expect($broadcasts->of(Signal::TASK_CHANGED))->toBe([[
        'channels' => $channels,
        'event' => Signal::TASK_CHANGED,
        'payload' => ['taskId' => $task->id, 'version' => $task->version, 'deleted' => false],
    ]])->and($task->version)->toBeGreaterThan(1);

    $broadcasts->sent = [];
    $before = null;
    DB::transaction(function () use ($task, $broadcasts, &$before): void {
        app(RenameTask::class)->handle($task, 'First new name');

        try {
            DB::transaction(function () use ($task): void {
                app(RenameTask::class)->handle($task, 'A name that is rolled back');

                throw new RuntimeException('Roll back the savepoint.');
            });
        } catch (RuntimeException) {
        }

        app(RenameTask::class)->handle($task->refresh(), 'Final name');
        $before = count($broadcasts->sent);
    });

    $committed = $task->refresh()->version;

    expect($before)->toBe(0)
        ->and($task->title)->toBe('Final name')
        ->and($broadcasts->sent)->toHaveCount(1)
        ->and($broadcasts->sent[0]['payload'])->toBe(['taskId' => $task->id, 'version' => $committed, 'deleted' => false]);

    $broadcasts->sent = [];
    expect(static fn () => DB::transaction(static function () use ($task): void {
        app(RenameTask::class)->handle($task, 'A name that never lands');

        throw new RuntimeException('Roll everything back.');
    }))->toThrow(RuntimeException::class);

    expect($broadcasts->sent)->toBe([])
        ->and($task->refresh()->version)->toBe($committed);
});

it('signals a deletion with what it captured before the row was gone', function (): void {
    $broadcasts = recordBroadcasts();
    [$visitor, $token] = visitor();
    $task = apiTask($token);
    $broadcasts->sent = [];

    expect(static fn () => DB::transaction(static function () use ($token, $task): void {
        api($token)->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

        throw new RuntimeException('Keep the task after all.');
    }))->toThrow(RuntimeException::class);

    expect($broadcasts->sent)->toBe([])
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();

    $before = $task->refresh()->version;
    api($token)->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse()
        ->and($broadcasts->sent)->toHaveCount(1)
        ->and($broadcasts->sent[0]['channels'])->toBe(["private-task.{$task->id}", "private-greenhouse.{$visitor->id}"])
        ->and($broadcasts->sent[0]['payload']['deleted'])->toBeTrue()
        ->and($broadcasts->sent[0]['payload']['version'])->toBeGreaterThanOrEqual($before);
});
