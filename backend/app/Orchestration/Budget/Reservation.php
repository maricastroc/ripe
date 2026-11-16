<?php

declare(strict_types=1);

namespace App\Orchestration\Budget;

final readonly class Reservation
{
    public function __construct(
        public string $day,
        public int $amount,
        public ?string $visitorId,
    ) {}
}
