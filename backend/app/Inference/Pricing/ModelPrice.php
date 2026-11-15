<?php

declare(strict_types=1);

namespace App\Inference\Pricing;

use InvalidArgumentException;

final readonly class ModelPrice
{
    public function __construct(
        public int $inputPerMillion,
        public int $cachedInputPerMillion,
        public int $cacheWritePerMillion,
        public int $outputPerMillion,
        public ?string $checkedOn = null,
    ) {
        foreach ([$inputPerMillion, $cachedInputPerMillion, $cacheWritePerMillion, $outputPerMillion] as $price) {
            if ($price < 0) {
                throw new InvalidArgumentException('Prices cannot be negative.');
            }
        }
    }

    /** @param array<array-key, mixed> $config */
    public static function fromDollars(array $config): self
    {
        $dollars = static function (string $key) use ($config): ?float {
            $value = $config[$key] ?? null;

            if ($value === null) {
                return null;
            }

            if (! is_numeric($value)) {
                throw new InvalidArgumentException("The {$key} price must be a number of dollars per million tokens.");
            }

            return (float) $value;
        };

        $input = $dollars('input') ?? throw new InvalidArgumentException('An input price is required.');
        $output = $dollars('output') ?? throw new InvalidArgumentException('An output price is required.');
        $checkedOn = $config['checked_on'] ?? null;

        return new self(
            inputPerMillion: self::microdollars($input),
            cachedInputPerMillion: self::microdollars($dollars('cached_input') ?? $input),
            cacheWritePerMillion: self::microdollars($dollars('cache_write') ?? $input * 1.25),
            outputPerMillion: self::microdollars($output),
            checkedOn: is_string($checkedOn) ? $checkedOn : null,
        );
    }

    private static function microdollars(float $dollars): int
    {
        return (int) round($dollars * 1_000_000);
    }
}
