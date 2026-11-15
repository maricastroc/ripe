<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/** @implements CastsAttributes<array<string, mixed>, mixed> */
final class JsonObject implements CastsAttributes
{
    /** @return array<string, mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException("The {$key} column must hold JSON text.");
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new UnexpectedValueException("The {$key} column must hold a JSON object.");
        }

        $object = [];

        foreach ($decoded as $name => $entry) {
            $object[(string) $name] = $entry;
        }

        return $object;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("The {$key} attribute must be a map of names to values.");
        }

        return json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
