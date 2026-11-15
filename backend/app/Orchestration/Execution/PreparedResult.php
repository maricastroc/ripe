<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Agent\Results\Interpretation;
use App\Agent\Results\Plan;
use App\Agent\Results\Review;

final readonly class PreparedResult
{
    /** @param array<string, mixed> $inputs */
    public function __construct(
        public Interpretation|Plan|Review|WrittenPiece $value,
        public array $inputs,
    ) {}
}
