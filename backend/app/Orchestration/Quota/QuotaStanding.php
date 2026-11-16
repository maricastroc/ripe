<?php

declare(strict_types=1);

namespace App\Orchestration\Quota;

use App\Enums\QuotaWait;
use Carbon\CarbonImmutable;

final readonly class QuotaStanding
{
    public function __construct(
        public ?QuotaWait $wait,
        public ?CarbonImmutable $resumesAt,
    ) {}

    public function resting(): bool
    {
        return $this->wait !== null;
    }
}
