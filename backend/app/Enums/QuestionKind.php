<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionKind: string
{
    case Optional = 'optional';
    case Essential = 'essential';
}
