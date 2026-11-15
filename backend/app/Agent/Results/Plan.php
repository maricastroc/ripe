<?php

declare(strict_types=1);

namespace App\Agent\Results;

final readonly class Plan
{
    /** @param list<PlannedStep> $steps */
    public function __construct(public array $steps) {}
}
