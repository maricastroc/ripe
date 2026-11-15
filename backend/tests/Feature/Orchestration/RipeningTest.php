<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\Stage;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Growth\GrowthCalculator;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Orchestration\NextRun;
use App\Orchestration\RunDispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

it('ripens a task through interpretation, plan, pieces and review', function (): void {
    fakeAgent();
    $task = ripen(plantTask());

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and(app(GrowthCalculator::class)->forTask($task)->stage)->toBe(Stage::Ripe)
        ->and($task->steps()->where('status', StepStatus::Done)->count())->toBe(3)
        ->and($task->artifacts()->count())->toBe(4)
        ->and(AgentRun::query()->where('task_id', $task->id)->orderBy('created_at')->orderBy('id')->pluck('kind')->map->value->all())->toBe(['interpret', 'plan', 'generate', 'generate', 'generate', 'review'])
        ->and(RunAttempt::query()->pluck('outcome')->unique()->all())->toBe([AttemptOutcome::Succeeded]);

    expectJournalConsistent($task);
});

it('rests between automatic runs at the configured pace', function (): void {
    Config::set('ripe.agent.pace', 'steady');
    fakeAgent();
    $task = plantTask();

    drainAgentQueue();
    $plan = AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Plan)->sole();

    expect($plan->deferred_reason)->toBe(RunDeferral::Pace)
        ->and((int) round($plan->created_at?->diffInSeconds($plan->available_at)))->toBe(6)
        ->and(drainAgentQueue())->toBe(0);
});

it('dispatches the next run atomically with the state that asks for it', function (): void {
    $task = Task::factory()->ripening()->create();

    try {
        DB::transaction(function () use ($task): void {
            app(RunDispatcher::class)->schedule($task, NextRun::interpret());

            throw new RuntimeException('The surrounding change failed.');
        });
    } catch (RuntimeException) {
    }

    expect(AgentRun::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    DB::transaction(fn () => app(RunDispatcher::class)->schedule($task, NextRun::interpret()));

    expect(AgentRun::query()->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'agent')->count())->toBe(1);
});

it('lets ripe:simulate ripen a task through real queue workers', function (): void {
    $this->artisan('ripe:simulate', ['--pace' => 'instant', '--timeout' => 60])->assertSuccessful();

    $task = Task::query()->sole();

    expect($task->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});
