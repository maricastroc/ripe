<?php

declare(strict_types=1);

namespace App\Growth;

use App\Enums\Stage;

final readonly class Growth
{
    public function __construct(
        public Stage $stage,
        public float $maturity,
        public int $stepsDone,
        public int $stepsTotal,
    ) {}
}
