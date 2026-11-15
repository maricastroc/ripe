<?php

declare(strict_types=1);

namespace App\Enums;

enum ArtifactKind: string
{
    case Step = 'step';
    case Review = 'review';
}
