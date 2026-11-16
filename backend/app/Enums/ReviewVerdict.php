<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewVerdict: string
{
    case Ready = 'ready';
    case Almost = 'almost';
    case Draft = 'draft';

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Ready => __('Ready to use, with small tweaks', [], $locale),
            self::Almost => __('Almost there, but it needs a few additions', [], $locale),
            self::Draft => __('A good base, but still a draft', [], $locale),
        };
    }
}
