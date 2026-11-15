<?php

declare(strict_types=1);

namespace App\Agent\Results;

use App\Enums\QuestionKind;

final readonly class InterpretedQuestion
{
    public function __construct(
        public string $question,
        public string $why,
        public QuestionKind $kind,
    ) {}
}
