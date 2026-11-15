<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\Pace;

final readonly class AgentPreferences
{
    public function __construct(
        public Pace $pace,
        public bool $paused,
        public ?string $model,
    ) {}
}
