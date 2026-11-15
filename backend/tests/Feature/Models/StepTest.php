<?php

declare(strict_types=1);

use App\Models\Question;
use App\Models\Step;
use App\Models\Task;

it('builds only on earlier steps of the same task', function (): void {
    $task = Task::factory()->planned()->create();
    $first = Step::factory()->for($task)->at(0)->create();
    $second = Step::factory()->for($task)->at(1)->create();
    $elsewhere = Step::factory()->at(0)->create();

    $second->dependOn($first);

    expect($second->dependencies()->pluck('steps.id')->all())->toBe([$first->id])
        ->and($first->dependents()->pluck('steps.id')->all())->toBe([$second->id])
        ->and(fn () => $first->dependOn($second))->toThrow(DomainException::class)
        ->and(fn () => $first->dependOn($first))->toThrow(DomainException::class)
        ->and(fn () => $second->dependOn($elsewhere))->toThrow(DomainException::class);
});

it('can only be held back by essential questions of its own task', function (): void {
    $task = Task::factory()->planned()->create();
    $step = Step::factory()->for($task)->create();
    $essential = Question::factory()->for($task)->essential()->at(0)->create();
    $optional = Question::factory()->for($task)->at(1)->create();
    $elsewhere = Question::factory()->essential()->create();

    $step->requireAnswer($essential);

    expect($step->requirements()->pluck('questions.id')->all())->toBe([$essential->id])
        ->and($essential->requiredBy()->pluck('steps.id')->all())->toBe([$step->id])
        ->and(fn () => $step->requireAnswer($optional))->toThrow(DomainException::class)
        ->and(fn () => $step->requireAnswer($elsewhere))->toThrow(DomainException::class);
});
