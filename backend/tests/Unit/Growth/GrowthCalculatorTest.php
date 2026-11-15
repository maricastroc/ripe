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
    'an interpreted request' => [true, false, false, 0, 0, Stage::Sprout, 0.12],
    'a plan without steps' => [true, true, false, 0, 0, Stage::Sprout, 0.25],
    'a plan with steps' => [true, true, false, 0, 5, Stage::Leafing, 0.25],
    'the first piece' => [true, true, false, 1, 5, Stage::Blossom, 0.37],
    'two of five pieces' => [true, true, false, 2, 5, Stage::Blossom, 0.49],
    'three of five pieces' => [true, true, false, 3, 5, Stage::Fruit, 0.61],
    'the only piece of one' => [true, true, false, 1, 1, Stage::Blossom, 0.85],
    'one of two pieces' => [true, true, false, 1, 2, Stage::Blossom, 0.55],
    'two of two pieces' => [true, true, false, 2, 2, Stage::Fruit, 0.85],
    'two of three pieces' => [true, true, false, 2, 3, Stage::Fruit, 0.65],
    'everything written' => [true, true, false, 5, 5, Stage::Fruit, 0.85],
    'reviewed with half the pieces' => [true, true, true, 2, 4, Stage::Ripe, 0.70],
    'everything written and reviewed' => [true, true, true, 5, 5, Stage::Ripe, 1.0],
]);

it('refuses impossible step counts', function (int $done, int $total): void {
    expect(fn () => (new GrowthCalculator)->calculate(true, true, false, $done, $total))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'more done than planned' => [3, 2],
    'negative done' => [-1, 2],
    'negative total' => [0, -1],
]);
