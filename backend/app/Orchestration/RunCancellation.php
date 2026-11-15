<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\EventActor;
use App\Enums\RunStatus;
use App\Enums\TaskEventType;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\Transactions;
use Carbon\CarbonImmutable;

final class RunCancellation
{
    public function cancelActive(Task $task, EventActor $actor): void
    {
        Transactions::require('Cancelling runs');

        $now = CarbonImmutable::now();
        $runs = AgentRun::query()
            ->where('task_id', $task->id)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            if ($run->status === RunStatus::Queued) {
                $run->cancel($now);
                TaskEvent::record($task, TaskEventType::RunCancelled, ['run_id' => $run->id, 'while' => 'queued'], $actor, $run);

                continue;
            }

            $run->requestCancellation($now);
        }
    }
}
