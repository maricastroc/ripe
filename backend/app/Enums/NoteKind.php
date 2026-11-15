<?php

declare(strict_types=1);

namespace App\Enums;

enum NoteKind: string
{
    case User = 'user';
    case Info = 'info';
    case Work = 'work';
    case Done = 'done';
    case Warn = 'warn';
    case Error = 'error';
}
