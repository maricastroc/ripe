<?php

declare(strict_types=1);

namespace App\Inference;

use InvalidArgumentException;

final readonly class QuotaLimits
{
    public function __construct(
        public int $requestsPerMinute,
        public int $requestsPerDay,
        public int $tokensPerMinute,
        public int $tokensPerDay,
        public int $concurrency,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("The {$name} quota must be at least 1.");
            }
        }
    }

    /** @param array<array-key, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $value = static function (string $key) use ($config): int {
            $raw = $config[$key] ?? null;

            if (! is_numeric($raw)) {
                throw new InvalidArgumentException("The {$key} quota must be configured as a number.");
            }

            return (int) $raw;
        };

        return new self(
            requestsPerMinute: $value('requests_per_minute'),
            requestsPerDay: $value('requests_per_day'),
            tokensPerMinute: $value('tokens_per_minute'),
            tokensPerDay: $value('tokens_per_day'),
            concurrency: $value('concurrency'),
        );
    }
}
