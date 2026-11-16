<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\EventActor;
use App\Models\Task;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\RunCancellation;
use App\Realtime\TaskChanges;
use App\Support\Transactions;
use Carbon\CarbonImmutable;

final readonly class ForgetTask
{
    public function __construct(
        private RunCancellation $cancellation,
        private InferenceBudget $budget,
        private TaskChanges $changes,
    ) {}

    public function handle(Task $task, EventActor $actor): void
    {
        Transactions::require('Forgetting a task');

        $this->cancellation->cancelActive($task, $actor);
        $this->budget->settleOpenReservations($task, CarbonImmutable::now());
        $this->changes->deleted($task);
        $task->delete();
    }
}
