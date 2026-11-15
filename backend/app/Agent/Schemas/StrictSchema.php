<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

final class StrictSchema
{
    private const array KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'description'];

    private const array TYPES = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];

    private const int MAX_DEPTH = 10;

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    public static function violations(array $schema): array
    {
        $problems = [];

        if (($schema['type'] ?? null) !== 'object') {
            $problems[] = '$ must be an object schema';
        }

        self::walk($schema, '$', 0, $problems);

        return $problems;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $problems
     */
    private static function walk(array $node, string $path, int $depth, array &$problems): void
    {
        if ($depth > self::MAX_DEPTH) {
            $problems[] = "{$path} nests deeper than ".self::MAX_DEPTH.' levels';

            return;
        }

        foreach (array_keys($node) as $keyword) {
            if (! in_array($keyword, self::KEYWORDS, true)) {
                $problems[] = "{$path} uses the unsupported keyword {$keyword}";
            }
        }

        $types = $node['type'] ?? null;
        $types = is_array($types) ? $types : [$types];

        foreach ($types as $type) {
            if (! in_array($type, self::TYPES, true)) {
                $problems[] = "{$path} has an invalid type";
            }
        }

        if (in_array('object', $types, true)) {
            self::checkObject($node, $path, $depth, $problems);
        }

        if (in_array('array', $types, true)) {
            $items = $node['items'] ?? null;

            if (! is_array($items)) {
                $problems[] = "{$path} is an array without items";
            } else {
                self::walk($items, "{$path}[]", $depth + 1, $problems);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $problems
     */
    private static function checkObject(array $node, string $path, int $depth, array &$problems): void
    {
        if (($node['additionalProperties'] ?? null) !== false) {
            $problems[] = "{$path} must set additionalProperties to false";
        }

        $properties = $node['properties'] ?? [];
        $properties = is_array($properties) ? $properties : [];
        $required = $node['required'] ?? [];
        $required = array_values(array_filter(is_array($required) ? $required : [], is_string(...)));
        $names = array_map(strval(...), array_keys($properties));

        if (array_diff($names, $required) !== [] || array_diff($required, $names) !== []) {
            $problems[] = "{$path} must list every property, and only its properties, as required";
        }

        foreach ($properties as $name => $property) {
            if (! is_array($property)) {
                $problems[] = "{$path}.{$name} must be a schema";

                continue;
            }

            self::walk($property, "{$path}.{$name}", $depth + 1, $problems);
        }
    }
}
