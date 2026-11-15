<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final readonly class Piece
{
    public function __construct(
        public string $title,
        public string $content,
    ) {}
}
