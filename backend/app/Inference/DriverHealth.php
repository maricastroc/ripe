<?php

declare(strict_types=1);

namespace App\Inference;

use App\Enums\Inference\HealthStatus;

final readonly class DriverHealth
{
    /** @param list<string> $models */
    public function __construct(
        public HealthStatus $status,
        public ?string $message = null,
        public array $models = [],
    ) {}

    /** @param list<string> $models */
    public static function available(array $models = []): self
    {
        return new self(HealthStatus::Available, models: $models);
    }

    public static function unreachable(string $message): self
    {
        return new self(HealthStatus::Unreachable, $message);
    }

    /** @param list<string> $models */
    public static function misconfigured(string $message, array $models = []): self
    {
        return new self(HealthStatus::Misconfigured, $message, $models);
    }

    public function isAvailable(): bool
    {
        return $this->status === HealthStatus::Available;
    }
}
