<?php

declare(strict_types=1);

namespace App\Enums;

use App\States\ChecksTransitions;
use App\States\StateMachine;

enum StepStatus: string implements StateMachine
{
    use ChecksTransitions;

    case Blocked = 'blocked';
    case Ready = 'ready';
    case Done = 'done';

    public function transitions(): array
    {
        return match ($this) {
            self::Blocked => [self::Ready],
            self::Ready => [self::Blocked, self::Done],
            self::Done => [],
        };
    }
}
