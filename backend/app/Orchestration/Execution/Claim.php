<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Models\AgentRun;
use App\Models\RunAttempt;
use Carbon\CarbonImmutable;

final readonly class Claim
{
    public function __construct(
        public AgentRun $run,
        public RunAttempt $attempt,
        public string $token,
        public CarbonImmutable $expiresAt,
        public ?PreparedCall $call = null,
    ) {}
}
