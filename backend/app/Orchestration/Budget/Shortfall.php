<?php

declare(strict_types=1);

namespace App\Orchestration\Budget;

use App\Enums\BudgetScope;
use Carbon\CarbonImmutable;

final readonly class Shortfall
{
    public function __construct(
        public BudgetScope $scope,
        public CarbonImmutable $retryAt,
    ) {}
}
