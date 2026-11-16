<?php

declare(strict_types=1);

namespace App\Orchestration\Quota;

use App\Enums\QuotaWait;
use Carbon\CarbonImmutable;

final readonly class QuotaShortfall
{
    public function __construct(
        public QuotaWait $wait,
        public CarbonImmutable $retryAt,
        public bool $known,
    ) {}
}
