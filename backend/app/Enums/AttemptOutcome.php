<?php

declare(strict_types=1);

namespace App\Enums;

enum AttemptOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case InvalidOutput = 'invalid_output';
    case DriverUnavailable = 'driver_unavailable';
    case RateLimited = 'rate_limited';
    case Cancelled = 'cancelled';
    case LeaseLost = 'lease_lost';
    case Interrupted = 'interrupted';

    public function consumesAttempt(): bool
    {
        return match ($this) {
            self::Failed, self::TimedOut, self::InvalidOutput => true,
            self::Succeeded, self::DriverUnavailable, self::RateLimited, self::Cancelled, self::LeaseLost, self::Interrupted => false,
        };
    }
}
