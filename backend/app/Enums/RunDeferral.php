<?php

declare(strict_types=1);

namespace App\Enums;

enum RunDeferral: string
{
    case Pace = 'pace';
    case Backoff = 'backoff';
    case RateLimited = 'rate_limited';
    case Budget = 'budget';
    case DriverUnavailable = 'driver_unavailable';
    case Paused = 'paused';
}
