<?php

declare(strict_types=1);

namespace App\Enums;

enum BudgetScope: string
{
    case Global = 'global';
    case Visitor = 'visitor';
}
