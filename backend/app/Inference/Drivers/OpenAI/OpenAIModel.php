<?php

declare(strict_types=1);

namespace App\Inference\Drivers\OpenAI;

use App\Inference\Pricing\ModelPrice;

final readonly class OpenAIModel
{
    private const string ANY_TIER = '*';

    /** @param array<string, ModelPrice> $prices */
    public function __construct(
        public string $id,
        public bool $reasoning = true,
        public bool $supportsTemperature = false,
        public array $prices = [],
    ) {}

    /** @param array<array-key, mixed> $catalogue */
    public static function fromCatalogue(string $id, array $catalogue): self
    {
        $entry = $catalogue[$id] ?? [];

        if (! is_array($entry)) {
            return new self($id);
        }

        return new self(
            id: $id,
            reasoning: ($entry['reasoning'] ?? true) === true,
            supportsTemperature: ($entry['supports_temperature'] ?? false) === true,
            prices: self::prices($entry['prices'] ?? null),
        );
    }

    public function priceFor(?string $tier): ?ModelPrice
    {
        $key = in_array($tier, [null, '', 'auto', 'default', 'standard'], true) ? 'standard' : $tier;

        return $this->prices[$key] ?? $this->prices[self::ANY_TIER] ?? null;
    }

    /** @return array<string, ModelPrice> */
    private static function prices(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        if (array_key_exists('input', $config)) {
            return [self::ANY_TIER => ModelPrice::fromDollars($config)];
        }

        $prices = [];

        foreach ($config as $tier => $tierPrices) {
            if (is_string($tier) && is_array($tierPrices)) {
                $prices[$tier] = ModelPrice::fromDollars($tierPrices);
            }
        }

        return $prices;
    }
}
