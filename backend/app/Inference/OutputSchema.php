<?php

declare(strict_types=1);

namespace App\Inference;

use InvalidArgumentException;

final readonly class OutputSchema
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public string $name,
        public array $schema,
    ) {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid schema name \"{$name}\".");
        }
    }
}
