<?php

declare(strict_types=1);

namespace App\Inference\Transport;

use InvalidArgumentException;

final readonly class Timeouts
{
    public function __construct(
        public float $connect,
        public float $firstByte,
        public float $idle,
        public float $total,
        public float $tick = 0.25,
    ) {
        foreach (['connect' => $connect, 'first byte' => $firstByte, 'idle' => $idle, 'total' => $total, 'tick' => $tick] as $name => $seconds) {
            if ($seconds <= 0) {
                throw new InvalidArgumentException("The {$name} timeout must be positive.");
            }
        }

        if ($firstByte > $total || $idle > $total) {
            throw new InvalidArgumentException('No timeout can exceed the total call timeout.');
        }
    }

    /** @param array<array-key, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $seconds = static function (string $key) use ($config): float {
            $value = $config[$key] ?? null;

            if (! is_numeric($value)) {
                throw new InvalidArgumentException("The {$key} timeout must be a number of seconds.");
            }

            return (float) $value;
        };

        return new self($seconds('connect'), $seconds('first_byte'), $seconds('idle'), $seconds('total'));
    }
}
