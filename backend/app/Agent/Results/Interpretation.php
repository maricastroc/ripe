<?php

declare(strict_types=1);

namespace App\Agent\Results;

use App\Enums\Deliverable;

final readonly class Interpretation
{
    /**
     * @param  list<string>  $context
     * @param  list<string>  $assumptions
     * @param  list<InterpretedQuestion>  $questions
     */
    public function __construct(
        public string $title,
        public ?string $language,
        public Deliverable $deliverable,
        public string $outcome,
        public array $context,
        public array $assumptions,
        public array $questions,
    ) {}
}
