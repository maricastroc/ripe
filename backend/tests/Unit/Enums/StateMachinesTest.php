<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\States\StateMachine;

/**
 * @param  list<StateMachine>  $cases
 * @return array<string, list<string>>
 */
function allowedTransitions(array $cases): array
{
    $matrix = [];

    foreach ($cases as $from) {
        $matrix[(string) $from->value] = [];

        foreach ($cases as $to) {
            if ($from->canTransitionTo($to)) {
                $matrix[(string) $from->value][] = (string) $to->value;
            }
        }
    }

    return $matrix;
}

it('allows exactly the documented task transitions', function (): void {
    expect(allowedTransitions(TaskStatus::cases()))->toBe([
        'dormant' => ['ripening', 'harvested'],
        'ripening' => ['dormant', 'waiting', 'ripe', 'stalled', 'harvested'],
        'waiting' => ['dormant', 'ripening', 'ripe', 'stalled', 'harvested'],
        'ripe' => ['ripening', 'harvested'],
        'stalled' => ['dormant', 'ripening', 'harvested'],
        'harvested' => ['dormant', 'ripe'],
    ]);
});

it('allows exactly the documented step transitions', function (): void {
    expect(allowedTransitions(StepStatus::cases()))->toBe([
        'blocked' => ['ready'],
        'ready' => ['blocked', 'done'],
        'done' => [],
    ]);
});

it('allows exactly the documented question transitions', function (): void {
    expect(allowedTransitions(QuestionStatus::cases()))->toBe([
        'open' => ['answered', 'skipped'],
        'answered' => ['open'],
        'skipped' => ['open', 'answered'],
    ]);
});

it('allows exactly the documented run transitions', function (): void {
    expect(allowedTransitions(RunStatus::cases()))->toBe([
        'queued' => ['running', 'cancelled'],
        'running' => ['queued', 'succeeded', 'failed', 'cancelled'],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
    ]);
});

it('rejects a forbidden transition with a descriptive error', function (): void {
    expect(fn () => StepStatus::Done->assertCanTransitionTo(StepStatus::Ready))
        ->toThrow(InvalidTransition::class, 'StepStatus cannot move from "done" to "ready".');
});
