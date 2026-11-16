<?php

declare(strict_types=1);

namespace App\Enums;

enum ChargeBasis: string
{
    case Reported = 'reported';
    case Estimated = 'estimated';
    case Unbilled = 'unbilled';
    case Reservation = 'reservation';

    public function confirmed(): bool
    {
        return $this === self::Reported || $this === self::Unbilled;
    }
}
