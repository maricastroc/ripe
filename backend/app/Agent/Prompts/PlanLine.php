<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final readonly class PlanLine
{
    public function __construct(
        public int $number,
        public string $title,
        public bool $done,
    ) {}
}
