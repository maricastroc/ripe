<?php

declare(strict_types=1);

namespace App\Inference\Contracts;

interface CallSupervisor
{
    public function tick(): void;
}
