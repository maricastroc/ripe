<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class TransactionRequired extends LogicException
{
    public static function for(string $operation): self
    {
        return new self(sprintf('%s must run inside a database transaction.', $operation));
    }
}
