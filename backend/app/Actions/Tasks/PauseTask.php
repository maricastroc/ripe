<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\RunCancellation;
use Illuminate\Support\Facades\DB;

final class PauseTask
{
    use LocksTasks;

    public function __construct(private readonly RunCancellation $cancellation) {}

    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = $this->locked($task);

            if (! in_array($task->status, [TaskStatus::Ripening, TaskStatus::Waiting, TaskStatus::Stalled], true)) {
                return;
            }

            $this->cancellation->cancelActive($task, EventActor::Visitor);
            $task->transitionTo(TaskStatus::Dormant, EventActor::Visitor);
            TaskEvent::record($task, TaskEventType::TaskPaused, [], EventActor::Visitor);
        });
    }
}
