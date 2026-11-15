<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;
use RuntimeException;

abstract class InferenceException extends RuntimeException
{
    use CarriesCallDetails;

    abstract public function scope(): ErrorScope;

    public function retryAfter(): ?float
    {
        return null;
    }
}
