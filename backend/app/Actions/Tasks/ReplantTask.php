<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Support\Facades\DB;

final class ReplantTask
{
    use LocksTasks;

    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = $this->locked($task);

            if ($task->status !== TaskStatus::Harvested) {
                return;
            }

            $task->transitionTo($task->ripe_at !== null ? TaskStatus::Ripe : TaskStatus::Dormant, EventActor::Visitor);
            TaskEvent::record($task, TaskEventType::TaskReplanted, [], EventActor::Visitor);
        });
    }
}
