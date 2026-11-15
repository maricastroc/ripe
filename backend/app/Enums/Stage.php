<?php

declare(strict_types=1);

namespace App\Enums;

enum Stage: string
{
    case Seed = 'seed';
    case Sprout = 'sprout';
    case Leafing = 'leafing';
    case Blossom = 'blossom';
    case Fruit = 'fruit';
    case Ripe = 'ripe';

    public function index(): int
    {
        return match ($this) {
            self::Seed => 0,
            self::Sprout => 1,
            self::Leafing => 2,
            self::Blossom => 3,
            self::Fruit => 4,
            self::Ripe => 5,
        };
    }
}
