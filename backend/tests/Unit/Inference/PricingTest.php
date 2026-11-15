<?php

declare(strict_types=1);

use App\Inference\Pricing\CostCalculator;
use App\Inference\Pricing\ModelPrice;
use App\Inference\Usage;

it('converts dollar prices into exact integers', function (): void {
    $price = ModelPrice::fromDollars(['input' => 0.4, 'cached_input' => 0.1, 'output' => 1.6, 'checked_on' => '2026-11-15']);

    expect($price->inputPerMillion)->toBe(400_000)
        ->and($price->cachedInputPerMillion)->toBe(100_000)
        ->and($price->cacheWritePerMillion)->toBe(500_000)
        ->and($price->outputPerMillion)->toBe(1_600_000)
        ->and($price->checkedOn)->toBe('2026-11-15');
});

it('charges cached, cache-written and uncached input at their own rates, and reasoning as output', function (): void {
    $price = new ModelPrice(inputPerMillion: 400_000, cachedInputPerMillion: 100_000, cacheWritePerMillion: 500_000, outputPerMillion: 1_600_000);
    $usage = new Usage(inputTokens: 1_000, outputTokens: 500, cachedInputTokens: 600, cacheWriteTokens: 200, reasoningTokens: 300);

    expect((new CostCalculator)->microdollars($usage, $price))->toBe(1040);
});

it('never loses money to floating point rounding', function (): void {
    $price = ModelPrice::fromDollars(['input' => 0.4, 'output' => 1.6]);

    expect((new CostCalculator)->microdollars(new Usage(1_000, 0), $price))->toBe(400);
});

it('rounds partial microdollars up', function (): void {
    $price = new ModelPrice(1, 1, 1, 1);

    expect((new CostCalculator)->microdollars(new Usage(1, 0), $price))->toBe(1);
});

it('keeps the ceiling at or above any real cost within the same limits', function (int $cached, int $written, int $output): void {
    $price = ModelPrice::fromDollars(['input' => 1.25, 'cached_input' => 0.125, 'output' => 10]);
    $costs = new CostCalculator;
    $actual = $costs->microdollars(new Usage(2_000, $output, $cached, $written), $price);

    expect($costs->ceiling(2_000, 3_000, $price))->toBeGreaterThanOrEqual($actual);
})->with([
    [0, 0, 3_000],
    [2_000, 0, 3_000],
    [0, 2_000, 3_000],
    [500, 500, 10],
]);

it('estimates usage at four characters per token and flags it', function (): void {
    $usage = Usage::estimate(4_001, 9);

    expect($usage->inputTokens)->toBe(1_001)
        ->and($usage->outputTokens)->toBe(3)
        ->and($usage->estimated)->toBeTrue();
});
