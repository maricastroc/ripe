<?php

declare(strict_types=1);

namespace App\States;

use BackedEnum;

interface StateMachine extends BackedEnum
{
    /** @return list<static> */
    public function transitions(): array;

    public function canTransitionTo(StateMachine $next): bool;

    public function assertCanTransitionTo(StateMachine $next): void;
}
