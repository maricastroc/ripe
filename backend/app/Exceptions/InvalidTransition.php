<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\States\StateMachine;
use DomainException;

final class InvalidTransition extends DomainException
{
    public static function between(StateMachine $from, StateMachine $to): self
    {
        return new self(sprintf(
            '%s cannot move from "%s" to "%s".',
            class_basename($from),
            $from->value,
            $to->value,
        ));
    }
}
