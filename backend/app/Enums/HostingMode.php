<?php

declare(strict_types=1);

namespace App\Enums;

enum HostingMode: string
{
    case Local = 'local';
    case Public = 'public';
}
