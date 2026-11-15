<?php

declare(strict_types=1);

namespace App\Enums;

use App\States\ChecksTransitions;
use App\States\StateMachine;

enum QuestionStatus: string implements StateMachine
{
    use ChecksTransitions;

    case Open = 'open';
    case Answered = 'answered';
    case Skipped = 'skipped';

    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::Answered, self::Skipped],
            self::Answered => [self::Open],
            self::Skipped => [self::Open, self::Answered],
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Open;
    }
}
