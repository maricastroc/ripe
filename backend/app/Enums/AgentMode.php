<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentMode: string
{
    case Idle = 'idle';
    case Working = 'working';
    case Paused = 'paused';
    case Halted = 'halted';
    case Resting = 'resting';
    case Offline = 'offline';
    case ModelMissing = 'model-missing';
    case Stopped = 'stopped';
}
