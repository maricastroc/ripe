<?php

declare(strict_types=1);

use App\Enums\Stage;
use App\Growth\GrowthCalculator;
use App\Models\AgentRun;
use App\Models\Step;
use App\Models\Task;

it('derives growth from committed milestones and steps', function (): void {
    $task = Task::factory()->planned()->create();
    Step::factory()->for($task)->at(0)->done()->create();
    Step::factory()->for($task)->at(1)->done()->create();
    Step::factory()->for($task)->at(2)->create();
    Step::factory()->for($task)->at(3)->create();

    $growth = (new GrowthCalculator)->forTask($task);

    expect($growth->stage)->toBe(Stage::Fruit)
        ->and($growth->stepsDone)->toBe(2)
        ->and($growth->stepsTotal)->toBe(4)
        ->and($growth->maturity)->toEqualWithDelta(0.55, 1e-9);
});

it('does not grow while work is only in progress or while time passes', function (): void {
    $task = Task::factory()->planned()->create();
    $step = Step::factory()->for($task)->create();
    $calculator = new GrowthCalculator;

    $before = $calculator->forTask($task);
    AgentRun::factory()->forStep($step)->running()->create();
    $this->travel(30)->days();
    $after = $calculator->forTask($task->fresh() ?? $task);

    expect($after)->toEqual($before)
        ->and($after->stage)->toBe(Stage::Leafing);
});
