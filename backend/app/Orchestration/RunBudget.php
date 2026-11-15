<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Task;

final class RunBudget
{
    public function __construct(private readonly AgentSettings $settings) {}

    public function remaining(Task $task): int
    {
        $used = AgentRun::query()->where('task_id', $task->id)->where('status', '!=', RunStatus::Cancelled)->count();

        return max(0, $this->settings->runBudget() - $used);
    }

    public function limit(): int
    {
        return $this->settings->runBudget();
    }
}
