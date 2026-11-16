<?php

declare(strict_types=1);

namespace App\Enums;

enum QuotaWait: string
{
    case Capacity = 'capacity';
    case Daily = 'daily';
}
