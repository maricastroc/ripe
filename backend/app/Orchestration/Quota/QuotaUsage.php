<?php

declare(strict_types=1);

namespace App\Orchestration\Quota;

use Carbon\CarbonImmutable;

final readonly class QuotaUsage
{
    public function __construct(
        public int $minuteRequests,
        public int $minuteTokens,
        public int $dayRequests,
        public int $dayTokens,
        public int $inFlight,
        public ?CarbonImmutable $minuteOldest,
        public ?CarbonImmutable $blockedUntil,
    ) {}
}
