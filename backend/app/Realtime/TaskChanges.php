<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Models\Task;
use App\Support\Transactions;
use Illuminate\Support\Facades\DB;

final class TaskChanges
{
    /** @var array<string, TaskChange> */
    private array $committed = [];

    public function __construct(private readonly Relay $relay) {}

    public function changed(Task $task): void
    {
        $this->remember(TaskChange::of($task));
    }

    public function deleted(Task $task): void
    {
        $this->remember(TaskChange::of($task, deleted: true));
    }

    public function flush(): void
    {
        if ($this->committed === []) {
            return;
        }

        $changes = $this->committed;
        $this->committed = [];

        foreach ($changes as $change) {
            $this->relay->send(Signal::taskChanged($change));
        }
    }

    private function remember(TaskChange $change): void
    {
        if (! $this->relay->enabled()) {
            return;
        }

        Transactions::require('Signalling a task change');

        DB::afterCommit(function () use ($change): void {
            $known = $this->committed[$change->taskId] ?? null;
            $this->committed[$change->taskId] = $known === null ? $change : $known->merge($change);
        });
    }
}
