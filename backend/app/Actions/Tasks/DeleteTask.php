<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Exceptions\TaskActionRejected;
use App\Models\Task;
use App\Orchestration\RunCancellation;
use Illuminate\Support\Facades\DB;

final readonly class DeleteTask
{
    use LocksTasks;

    public function __construct(private RunCancellation $cancellation) {}

    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = $this->locked($task);

            if ($task->is_example) {
                throw new TaskActionRejected('Examples can only be read.');
            }

            $this->cancellation->cancelActive($task, EventActor::Visitor);
            $task->delete();
        });
    }
}
