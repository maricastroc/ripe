<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\AgentMode;
use App\Enums\HostingMode;
use App\Enums\RestReason;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;

final readonly class AgentStatus
{
    /** @param list<string> $models */
    public function __construct(
        public HostingMode $hosting,
        public AgentMode $mode,
        public ?string $message,
        public string $driver,
        public string $model,
        public bool $online,
        public array $models,
        public ?AgentPreferences $settings,
        public ?AgentRun $current,
        public bool $elsewhere,
        public int $queued,
        public ?CarbonImmutable $resumesAt = null,
        public ?RestReason $rest = null,
    ) {}
}
