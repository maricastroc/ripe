<?php

declare(strict_types=1);

namespace App\States;

use App\Exceptions\InvalidTransition;

trait ChecksTransitions
{
    public function canTransitionTo(StateMachine $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function assertCanTransitionTo(StateMachine $next): void
    {
        if (! $this->canTransitionTo($next)) {
            throw InvalidTransition::between($this, $next);
        }
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
