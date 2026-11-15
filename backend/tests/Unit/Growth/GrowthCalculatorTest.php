<?php

declare(strict_types=1);

use App\Enums\Stage;
use App\Growth\GrowthCalculator;

it('reproduces the growth table of the original application', function (
    bool $interpreted,
    bool $planned,
    bool $reviewed,
    int $done,
    int $total,
    Stage $stage,
    float $maturity,
): void {
    $growth = (new GrowthCalculator)->calculate($interpreted, $planned, $reviewed, $done, $total);

    expect($growth->stage)->toBe($stage)
        ->and($growth->maturity)->toEqualWithDelta($maturity, 1e-9)
        ->and($growth->stepsDone)->toBe($done)
        ->and($growth->stepsTotal)->toBe($total);
})->with([
    'a seed' => [false, false, false, 0, 0, Stage::Seed, 0.0],
    'a plan without steps' => [true, true, false, 0, 0, Stage::Sprout, 0.25],
    'a plan with steps' => [true, true, false, 0, 5, Stage::Leafing, 0.25],
    'the first piece' => [true, true, false, 1, 5, Stage::Blossom, 0.37],
    'two of five pieces' => [true, true, false, 2, 5, Stage::Blossom, 0.49],
    'three of five pieces' => [true, true, false, 3, 5, Stage::Fruit, 0.61],
    'the only piece of one' => [true, true, false, 1, 1, Stage::Blossom, 0.85],
    'everything written' => [true, true, false, 5, 5, Stage::Fruit, 0.85],
    'reviewed with half the pieces' => [true, true, true, 2, 4, Stage::Ripe, 0.70],
]);
