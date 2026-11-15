<?php

declare(strict_types=1);

namespace App\Inference\Contracts;

interface StreamObserver
{
    public function onDelta(string $delta): void;
}
