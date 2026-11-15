<?php

declare(strict_types=1);

namespace App\Enums;

enum StepPhase: string
{
    case Blocked = 'blocked';
    case Ready = 'ready';
    case Working = 'working';
    case Done = 'done';
}
