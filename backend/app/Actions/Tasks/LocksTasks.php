<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Support\Transactions;

trait LocksTasks
{
    private function locked(Task $task): Task
    {
        Transactions::require('Changing a task');

        return Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
    }
}
