<?php

declare(strict_types=1);

namespace App\Enums;

use App\States\ChecksTransitions;
use App\States\StateMachine;

enum TaskStatus: string implements StateMachine
{
    use ChecksTransitions;

    case Dormant = 'dormant';
    case Ripening = 'ripening';
    case Waiting = 'waiting';
    case Ripe = 'ripe';
    case Stalled = 'stalled';
    case Harvested = 'harvested';

    public function transitions(): array
    {
        return match ($this) {
            self::Dormant => [self::Ripening, self::Harvested],
            self::Ripening => [self::Waiting, self::Ripe, self::Stalled, self::Dormant, self::Harvested],
            self::Waiting => [self::Ripening, self::Ripe, self::Stalled, self::Dormant, self::Harvested],
            self::Ripe => [self::Ripening, self::Harvested],
            self::Stalled => [self::Ripening, self::Dormant, self::Harvested],
            self::Harvested => [self::Ripe, self::Dormant],
        };
    }
}
