<?php

declare(strict_types=1);

namespace App\Inference;

use App\Inference\Contracts\CallSupervisor;

final class NullSupervisor implements CallSupervisor
{
    public function tick(): void {}
}
