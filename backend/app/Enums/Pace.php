<?php

declare(strict_types=1);

namespace App\Enums;

enum Pace: string
{
    case Calm = 'calm';
    case Steady = 'steady';
    case Brisk = 'brisk';
    case Instant = 'instant';

    public function seconds(): int
    {
        return match ($this) {
            self::Calm => 30,
            self::Steady => 6,
            self::Brisk => 1,
            self::Instant => 0,
        };
    }
}
