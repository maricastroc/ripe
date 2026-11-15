<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

use App\Agent\Schemas\StructuredSchema;

final readonly class Prompt
{
    /** @param StructuredSchema<object>|null $schema */
    public function __construct(
        public string $instructions,
        public string $input,
        public ?StructuredSchema $schema = null,
    ) {}
}
