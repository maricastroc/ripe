<?php

declare(strict_types=1);

namespace App\Enums;

enum ArtifactVersionSource: string
{
    case Generate = 'generate';
    case Revise = 'revise';
    case Review = 'review';
    case UserEdit = 'user_edit';
    case Restore = 'restore';

    public function isProducedByAgent(): bool
    {
        return match ($this) {
            self::Generate, self::Revise, self::Review => true,
            self::UserEdit, self::Restore => false,
        };
    }
}
