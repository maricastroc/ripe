<?php

declare(strict_types=1);

namespace App\Inference\Drivers\OpenAI;

use App\Inference\Pricing\ModelPrice;

final readonly class OpenAIModel
{
    public function __construct(
        public string $id,
        public bool $reasoning = true,
        public bool $supportsTemperature = false,
        public ?ModelPrice $price = null,
    ) {}

    /** @param array<array-key, mixed> $catalogue */
    public static function fromCatalogue(string $id, array $catalogue): self
    {
        $entry = $catalogue[$id] ?? [];

        if (! is_array($entry)) {
            return new self($id);
        }

        $prices = $entry['prices'] ?? null;

        return new self(
            id: $id,
            reasoning: ($entry['reasoning'] ?? true) === true,
            supportsTemperature: ($entry['supports_temperature'] ?? false) === true,
            price: is_array($prices) ? ModelPrice::fromDollars($prices) : null,
        );
    }
}
