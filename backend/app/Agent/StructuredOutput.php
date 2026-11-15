<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Schemas\StructuredSchema;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Inference\Support\Json;

final class StructuredOutput
{
    /**
     * @template TResult of object
     *
     * @param  StructuredSchema<TResult>  $schema
     * @return TResult
     */
    public function decode(string $text, StructuredSchema $schema): object
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new InvalidModelOutput('The model did not return JSON.');
        }

        $data = Json::decodeObject(substr($text, $start, $end - $start + 1))
            ?? throw new InvalidModelOutput('The model returned incomplete JSON.');

        return $schema->parse($data);
    }
}
