<?php

declare(strict_types=1);

use App\Actions\Tasks\DeleteTask;
use App\Inference\Drivers\Fake\FakeReply;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;

it('lets a worker stop quietly when its task is deleted in mid-stream', function (): void {
    fakeAgent(first: [static fn (): FakeReply => FakeReply::text('An answer for a task that is gone.')->afterSilence(4)->onTick(2, static fn () => app(DeleteTask::class)->handle(Task::query()->sole()))]);
    plantTask();

    drainAgentQueue();

    expect(Task::query()->count())->toBe(0)
        ->and(AgentRun::query()->count())->toBe(0)
        ->and(RunAttempt::query()->count())->toBe(0)
        ->and(TaskEvent::query()->count())->toBe(0);
});
