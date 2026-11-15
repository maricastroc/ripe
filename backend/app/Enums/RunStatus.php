<?php

declare(strict_types=1);

namespace App\Enums;

use App\States\ChecksTransitions;
use App\States\StateMachine;

enum RunStatus: string implements StateMachine
{
    use ChecksTransitions;

    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Cancelled],
            self::Running => [self::Queued, self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
