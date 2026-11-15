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

final class HarvestTask
{
    use LocksTasks;

    public function __construct(private readonly RunCancellation $cancellation) {}

    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = $this->locked($task);

            if ($task->status === TaskStatus::Harvested) {
                return;
            }

            $this->cancellation->cancelActive($task, EventActor::Visitor);
            $task->transitionTo(TaskStatus::Harvested, EventActor::Visitor);
            TaskEvent::record($task, TaskEventType::TaskHarvested, [], EventActor::Visitor);
        });
    }
}
