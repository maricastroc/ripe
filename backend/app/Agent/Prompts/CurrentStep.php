<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

use App\Enums\OutputFormat;

final readonly class CurrentStep
{
    public function __construct(
        public int $number,
        public string $title,
        public string $description,
        public OutputFormat $output,
    ) {}
}
