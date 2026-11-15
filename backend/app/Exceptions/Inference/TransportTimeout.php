<?php

declare(strict_types=1);

namespace App\Exceptions\Inference;

use App\Enums\Inference\TimeoutKind;
use RuntimeException;

final class TransportTimeout extends RuntimeException
{
    public function __construct(public readonly TimeoutKind $kind, public readonly int $receivedBytes)
    {
        parent::__construct($kind->describe());
    }
}
