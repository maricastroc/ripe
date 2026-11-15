<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\TaskOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DeferTask
{
    use LocksTasks;

    public function __construct(private readonly TaskOrchestrator $orchestrator) {}

    public function handle(Task $task, ?CarbonImmutable $returnAt = null): void
    {
        DB::transaction(fn () => $this->within($this->locked($task), $returnAt));
    }

    public function within(Task $task, ?CarbonImmutable $returnAt = null): void
    {
        if ($task->is_example) {
            throw new TaskActionRejected('The agent does not work on examples.');
        }

        if ($task->status === TaskStatus::Harvested) {
            throw new TaskActionRejected('This task has already been harvested.');
        }

        if ($task->status === TaskStatus::Ripe) {
            throw new TaskActionRejected('This task is already ripe.');
        }

        $from = $task->status;
        $firstTime = $task->deferred_at === null;
        $task->deferred_at = CarbonImmutable::now();
        $task->return_at = $returnAt ?? $task->return_at;
        $task->save();

        TaskEvent::record($task, $firstTime ? TaskEventType::TaskDeferred : TaskEventType::TaskResumed, array_filter([
            'from' => $from->value,
            'return_at' => $task->return_at?->toIso8601String(),
        ], static fn (?string $value): bool => $value !== null), EventActor::Visitor);

        $task->transitionTo(TaskStatus::Ripening, EventActor::Visitor);
        $this->orchestrator->reconcile($task);
    }
}
