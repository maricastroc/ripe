<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use RuntimeException;

final class TransportFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $curlError = 0, public readonly int $receivedBytes = 0)
    {
        parent::__construct($message);
    }
}
