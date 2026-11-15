<?php

declare(strict_types=1);

namespace App\Enums;

enum EventActor: string
{
    case Visitor = 'visitor';
    case Agent = 'agent';
    case System = 'system';
}
