<?php

declare(strict_types=1);

namespace App\Inference\Support;

use App\Inference\Transport\StreamResponse;
use DateTimeImmutable;

final class RetryHint
{
    public static function from(StreamResponse $response): ?float
    {
        $hints = array_filter([
            self::retryAfter($response->header('retry-after')),
            self::duration($response->header('x-ratelimit-reset-requests')),
            self::duration($response->header('x-ratelimit-reset-tokens')),
        ], static fn (?float $seconds): bool => $seconds !== null);

        return $hints === [] ? null : max($hints);
    }

    public static function retryAfter(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, trim($value));

        return $date === false ? null : max(0.0, (float) ($date->getTimestamp() - time()));
    }

    public static function duration(?string $value): ?float
    {
        if ($value === null || preg_match('/^(?:\d+(?:\.\d+)?(?:ms|h|m|s))+$/', $value) !== 1) {
            return null;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $value, $parts, PREG_SET_ORDER);

        $seconds = 0.0;

        foreach ($parts as [, $amount, $unit]) {
            $seconds += (float) $amount * match ($unit) {
                'h' => 3600,
                'm' => 60,
                's' => 1,
                default => 0.001,
            };
        }

        return $seconds;
    }
}
