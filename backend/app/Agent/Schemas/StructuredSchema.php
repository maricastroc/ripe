<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

use App\Inference\OutputSchema;

/** @template-covariant TResult of object */
interface StructuredSchema
{
    public function format(): OutputSchema;

    /**
     * @param  array<array-key, mixed>  $data
     * @return TResult
     */
    public function parse(array $data): object;
}
