<?php

declare(strict_types=1);

namespace App\Enums;

enum LeaseRenewal
{
    case Renewed;
    case Cancelled;
    case Lost;
}
