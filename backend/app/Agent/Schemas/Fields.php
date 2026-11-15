<?php

declare(strict_types=1);

namespace App\Agent\Schemas;

use App\Exceptions\Inference\InvalidModelOutput;
use BackedEnum;

final class Fields
{
    /** @var list<string> */
    private array $problems = [];

    /** @param array<array-key, mixed> $data */
    public function text(array $data, string $field, string $path, bool $required = true): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || ($required && trim($value) === '')) {
            $this->problems[] = "{$path}.{$field} must be a non-empty string";

            return '';
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function texts(array $data, string $field, string $path): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            $this->problems[] = "{$path}.{$field} must be a list";

            return [];
        }

        $texts = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $texts[] = trim($item);
            }
        }

        return $texts;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<int>
     */
    public function numbers(array $data, string $field, string $path): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            $this->problems[] = "{$path}.{$field} must be a list";

            return [];
        }

        return array_values(array_filter($value, is_int(...)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public function objects(array $data, string $field, string $path): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            $this->problems[] = "{$path}.{$field} must be a list";

            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public function choice(array $data, string $field, string $path, string $enum): ?BackedEnum
    {
        $value = $data[$field] ?? null;
        $choice = is_string($value) ? $enum::tryFrom($value) : null;

        if ($choice === null) {
            $this->problems[] = "{$path}.{$field} has an unexpected value";
        }

        return $choice;
    }

    public function problem(string $problem): void
    {
        $this->problems[] = $problem;
    }

    public function assertValid(): void
    {
        if ($this->problems !== []) {
            throw new InvalidModelOutput('The model\'s reply did not match the expected format: '.implode('; ', $this->problems).'.');
        }
    }
}
