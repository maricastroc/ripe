<?php

declare(strict_types=1);

namespace App\Orchestration\Budget;

use Carbon\CarbonImmutable;

final readonly class BudgetStanding
{
    public function __construct(
        public bool $resting,
        public ?CarbonImmutable $resumesAt,
    ) {}
}
