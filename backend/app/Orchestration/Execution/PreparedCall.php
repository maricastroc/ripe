<?php

declare(strict_types=1);

namespace App\Orchestration\Execution;

use App\Agent\Schemas\StructuredSchema;
use App\Inference\ModelRequest;

final readonly class PreparedCall
{
    /**
     * @param  StructuredSchema<object>|null  $schema
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public ModelRequest $request,
        public ?StructuredSchema $schema,
        public array $inputs,
    ) {}
}
