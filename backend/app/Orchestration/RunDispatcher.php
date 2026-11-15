<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\EventActor;
use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunOrigin;
use App\Enums\TaskEventType;
use App\Jobs\ExecuteRun;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;

final class RunDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly AgentSettings $settings,
    ) {}

    public function schedule(Task $task, NextRun $next, EventActor $actor = EventActor::System): AgentRun
    {
        Transactions::require('Scheduling a run');

        $now = CarbonImmutable::now();
        $pace = $next->kind === RunKind::Interpret || $next->origin === RunOrigin::Visitor ? 0 : $this->settings->pace()->seconds();

        $run = AgentRun::query()->create([
            'task_id' => $task->id,
            'kind' => $next->kind,
            'origin' => $next->origin,
            'step_id' => $next->step?->id,
            'artifact_id' => $next->artifact?->id,
            'base_version_id' => $next->baseVersionId,
            'instruction' => $next->instruction,
            'available_at' => $now->addSeconds($pace),
            'deferred_reason' => $pace > 0 ? RunDeferral::Pace : null,
            'max_attempts' => $this->settings->maxAttempts(),
        ]);

        TaskEvent::record($task, TaskEventType::RunScheduled, array_filter([
            'run_id' => $run->id,
            'kind' => $next->kind->value,
            'origin' => $next->origin->value,
            'step_id' => $next->step?->id,
            'artifact_id' => $next->artifact?->id,
            'available_at' => $run->available_at?->toIso8601String(),
        ], static fn (?string $value): bool => $value !== null), $actor, $run);

        $this->dispatch($run, $now);

        return $run;
    }

    public function dispatch(AgentRun $run, CarbonImmutable $now): void
    {
        $job = new ExecuteRun($run->id);

        if ($run->available_at !== null && $run->available_at->greaterThan($now)) {
            $job->delay($run->available_at);
        }

        $this->bus->dispatch($job);
        $run->wasDispatched($now);
    }
}
