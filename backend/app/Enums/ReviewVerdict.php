<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewVerdict: string
{
    case Ready = 'ready';
    case Almost = 'almost';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready to use, with small tweaks',
            self::Almost => 'Almost there, but it needs a few additions',
            self::Draft => 'A good base, but still a draft',
        };
    }
}
