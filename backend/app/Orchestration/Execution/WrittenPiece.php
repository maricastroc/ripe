<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

final readonly class WrittenPiece
{
    public function __construct(
        public string $content,
        public bool $truncated,
    ) {}
}
