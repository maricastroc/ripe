<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Enums\RunDeferral;
use Carbon\CarbonImmutable;

final readonly class RetryDecision
{
    private function __construct(
        public bool $retry,
        public bool $halt,
        public bool $consumesAttempt,
        public ?CarbonImmutable $at = null,
        public ?RunDeferral $reason = null,
    ) {}

    public static function retry(CarbonImmutable $at, RunDeferral $reason, bool $consumesAttempt): self
    {
        return new self(true, false, $consumesAttempt, $at, $reason);
    }

    public static function halt(CarbonImmutable $at): self
    {
        return new self(true, true, false, $at, RunDeferral::DriverUnavailable);
    }

    public static function fail(bool $consumesAttempt): self
    {
        return new self(false, false, $consumesAttempt);
    }
}
