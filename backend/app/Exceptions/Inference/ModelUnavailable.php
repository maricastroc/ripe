<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;
use Throwable;

final class ModelUnavailable extends InferenceException
{
    public function __construct(string $message, private readonly ?float $retryAfterSeconds = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function scope(): ErrorScope
    {
        return ErrorScope::Transient;
    }

    public function retryAfter(): ?float
    {
        return $this->retryAfterSeconds;
    }
}
