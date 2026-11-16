<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BudgetScope;
use RuntimeException;

final class BudgetExhausted extends RuntimeException
{
    public function __construct(public readonly BudgetScope $scope)
    {
        parent::__construct("The {$scope->value} budget cannot cover this call today.");
    }
}
