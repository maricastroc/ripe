<?php

declare(strict_types=1);

namespace App\Enums\Inference;

enum ReasoningEffort: string
{
    case None = 'none';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
    case Max = 'max';
}
