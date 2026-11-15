<?php

declare(strict_types=1);

namespace App\Enums\Inference;

enum ErrorScope: string
{
    case Transient = 'transient';
    case Attempt = 'attempt';
    case Run = 'run';
    case Driver = 'driver';

    public function consumesAttempt(): bool
    {
        return $this === self::Attempt;
    }
}
