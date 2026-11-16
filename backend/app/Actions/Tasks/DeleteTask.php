<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Exceptions\TaskActionRejected;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final readonly class DeleteTask
{
    use LocksTasks;

    public function __construct(private ForgetTask $forget) {}

    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $task = $this->locked($task);

            if ($task->is_example) {
                throw new TaskActionRejected(__('Examples can only be read.'));
            }

            $this->forget->handle($task, EventActor::Visitor);
        });
    }
}
