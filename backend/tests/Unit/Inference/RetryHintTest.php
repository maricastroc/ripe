<?php

declare(strict_types=1);

use App\Inference\Drivers\OpenAI\RetryHint;
use App\Inference\Transport\StreamResponse;

it('reads OpenAI reset durations', function (string $value, float $seconds): void {
    expect(RetryHint::duration($value))->toEqualWithDelta($seconds, 1e-9);
})->with([
    ['1s', 1.0],
    ['20ms', 0.02],
    ['6m0s', 360.0],
    ['1h2m3.5s', 3723.5],
    ['0s', 0.0],
]);

it('ignores malformed durations', function (string $value): void {
    expect(RetryHint::duration($value))->toBeNull();
})->with(['', 'soon', '5', '1d']);

it('reads Retry-After as seconds or as a date', function (): void {
    expect(RetryHint::retryAfter('2.5'))->toBe(2.5)
        ->and(RetryHint::retryAfter(gmdate(DATE_RFC7231, time() + 30)))->toBeGreaterThan(25.0)
        ->and(RetryHint::retryAfter('later'))->toBeNull();
});

it('waits for the longest of the hints it receives', function (): void {
    $response = new StreamResponse(429, ['retry-after' => '1', 'x-ratelimit-reset-requests' => '250ms', 'x-ratelimit-reset-tokens' => '6s'], '', null, 0, 0);

    expect(RetryHint::from($response))->toBe(6.0)
        ->and(RetryHint::from(new StreamResponse(429, [], '', null, 0, 0)))->toBeNull();
});
