<?php

declare(strict_types=1);

namespace App\Enums;

enum RunOrigin: string
{
    case Orchestrator = 'orchestrator';
    case Visitor = 'visitor';
}
