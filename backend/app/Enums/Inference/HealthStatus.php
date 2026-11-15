<?php

declare(strict_types=1);

namespace App\Enums\Inference;

enum HealthStatus: string
{
    case Available = 'available';
    case Unreachable = 'unreachable';
    case Misconfigured = 'misconfigured';
}
