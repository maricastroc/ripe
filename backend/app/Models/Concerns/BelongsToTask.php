<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Task;
use App\Support\Transactions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTask
{
    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function owningTask(): Task
    {
        return $this->task ?? throw (new ModelNotFoundException)->setModel(Task::class, [$this->task_id]);
    }

    protected function lockOwningTask(): Task
    {
        Transactions::require('Locking a task');

        $task = Task::query()->whereKey($this->task_id)->lockForUpdate()->firstOrFail();
        $this->setRelation('task', $task);

        return $task;
    }
}
