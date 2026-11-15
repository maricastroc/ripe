<?php

declare(strict_types=1);

namespace App\Enums;

enum Deliverable: string
{
    case Document = 'document';
    case Plan = 'plan';
    case Presentation = 'presentation';
    case Message = 'message';
    case Checklist = 'checklist';
    case Other = 'other';

    public function fruit(): Fruit
    {
        return match ($this) {
            self::Document => Fruit::Plum,
            self::Presentation => Fruit::Lemon,
            self::Message => Fruit::Fig,
            self::Plan, self::Checklist, self::Other => Fruit::Persimmon,
        };
    }
}
