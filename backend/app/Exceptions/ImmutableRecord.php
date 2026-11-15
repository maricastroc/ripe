<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class ImmutableRecord extends LogicException
{
    public static function cannotUpdate(string $model): self
    {
        return new self(sprintf('%s records are immutable and cannot be updated.', class_basename($model)));
    }

    public static function cannotDelete(string $model): self
    {
        return new self(sprintf('%s records are immutable and cannot be deleted on their own.', class_basename($model)));
    }
}
