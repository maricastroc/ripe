<?php

declare(strict_types=1);

namespace App\Enums\Inference;

enum TimeoutKind: string
{
    case FirstByte = 'first_byte';
    case Idle = 'idle';
    case Total = 'total';

    public function describe(): string
    {
        return match ($this) {
            self::FirstByte => 'The model did not start answering in time.',
            self::Idle => 'The model stopped sending output for too long.',
            self::Total => 'The model call took longer than allowed.',
        };
    }
}
