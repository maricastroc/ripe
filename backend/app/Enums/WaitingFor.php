<?php

declare(strict_types=1);

namespace App\Enums;

enum WaitingFor: string
{
    case Step = 'step';
    case Answer = 'answer';
}
