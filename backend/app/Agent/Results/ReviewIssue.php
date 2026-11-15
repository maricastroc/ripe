<?php

declare(strict_types=1);

namespace App\Agent\Results;

final readonly class ReviewIssue
{
    public function __construct(
        public string $where,
        public string $problem,
        public string $suggestion,
    ) {}
}
