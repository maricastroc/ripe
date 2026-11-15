<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use RuntimeException;

final class CallAborted extends RuntimeException
{
    use CarriesCallDetails;

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
