<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Groq;

use App\Enums\Inference\ReasoningEffort;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Inference\QuotaLimits;
use InvalidArgumentException;

final readonly class GroqModel
{
    /** @param array<string, int> $reasoningAllowance */
    public function __construct(
        public string $id,
        public bool $reasoning,
        public bool $supportsTemperature,
        public array $reasoningAllowance,
        public QuotaLimits $limits,
    ) {}

    /** @param array<array-key, mixed> $catalogue */
    public static function fromCatalogue(string $id, array $catalogue): self
    {
        $entry = $catalogue[$id] ?? null;

        if (! is_array($entry)) {
            throw new DriverMisconfigured("The Groq model {$id} is not in Ripe's catalogue, so its limits are unknown.");
        }

        $allowance = [];

        foreach (is_array($entry['reasoning_allowance'] ?? null) ? $entry['reasoning_allowance'] : [] as $effort => $tokens) {
            if (is_string($effort) && is_int($tokens)) {
                $allowance[$effort] = $tokens;
            }
        }

        try {
            $limits = QuotaLimits::fromConfig(is_array($entry['limits'] ?? null) ? $entry['limits'] : []);
        } catch (InvalidArgumentException $invalid) {
            throw new DriverMisconfigured("The Groq limits for {$id} are not configured: {$invalid->getMessage()}");
        }

        return new self(
            id: $id,
            reasoning: ($entry['reasoning'] ?? false) === true,
            supportsTemperature: ($entry['supports_temperature'] ?? true) === true,
            reasoningAllowance: $allowance,
            limits: $limits,
        );
    }

    public function effort(ReasoningEffort $requested): string
    {
        return match ($requested) {
            ReasoningEffort::None, ReasoningEffort::Minimal, ReasoningEffort::Low => 'low',
            ReasoningEffort::Medium => 'medium',
            ReasoningEffort::High, ReasoningEffort::ExtraHigh, ReasoningEffort::Max => 'high',
        };
    }
}
