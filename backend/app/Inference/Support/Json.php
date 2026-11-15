<?php

declare(strict_types=1);

namespace App\Inference\Support;

use JsonException;

final class Json
{
    /** @return array<array-key, mixed>|null */
    public static function decodeObject(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public static function get(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    public static function string(mixed $data, string $path): ?string
    {
        $value = self::get($data, $path);

        return is_string($value) ? $value : null;
    }

    public static function integer(mixed $data, string $path): int
    {
        $value = self::get($data, $path);

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** @return list<mixed> */
    public static function list(mixed $data, string $path): array
    {
        $value = self::get($data, $path);

        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
