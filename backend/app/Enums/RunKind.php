<?php

declare(strict_types=1);

namespace App\Enums;

enum RunKind: string
{
    case Interpret = 'interpret';
    case Plan = 'plan';
    case Generate = 'generate';
    case Revise = 'revise';
    case Review = 'review';

    public function targetsStep(): bool
    {
        return $this === self::Generate;
    }

    public function targetsArtifact(): bool
    {
        return $this === self::Revise;
    }
}
