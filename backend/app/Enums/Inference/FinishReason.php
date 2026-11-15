<?php

declare(strict_types=1);

namespace App\Enums\Inference;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
}
