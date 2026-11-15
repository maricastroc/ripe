<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;
use App\Enums\Inference\TimeoutKind;
use Throwable;

final class ModelTimeout extends InferenceException
{
    public function __construct(public readonly TimeoutKind $kind, ?Throwable $previous = null)
    {
        parent::__construct($kind->describe(), 0, $previous);
    }

    public function scope(): ErrorScope
    {
        return ErrorScope::Attempt;
    }
}
