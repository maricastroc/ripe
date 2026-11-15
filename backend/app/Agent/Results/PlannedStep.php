<?php

declare(strict_types=1);

namespace App\Agent\Results;

use App\Enums\OutputFormat;

final readonly class PlannedStep
{
    /**
     * @param  list<int>  $buildsOn
     * @param  list<int>  $requires
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $description,
        public OutputFormat $output,
        public array $buildsOn,
        public array $requires,
    ) {}
}
