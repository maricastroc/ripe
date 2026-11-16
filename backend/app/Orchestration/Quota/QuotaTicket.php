<?php

declare(strict_types=1);

namespace App\Orchestration\Quota;

final readonly class QuotaTicket
{
    public function __construct(
        public string $callId,
        public string $scope,
        public int $tokens,
    ) {}
}
