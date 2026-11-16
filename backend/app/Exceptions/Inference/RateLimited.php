<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\ErrorScope;

final class RateLimited extends InferenceException
{
    public function __construct(
        string $message,
        private readonly ?float $retryAfterSeconds = null,
        public readonly bool $daily = false,
    ) {
        parent::__construct($message);
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
