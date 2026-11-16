<?php

declare(strict_types=1);

namespace App\Enums;

enum RestReason: string
{
    case Capacity = 'capacity';
    case Daily = 'daily';
    case Budget = 'budget';
}
