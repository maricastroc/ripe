<?php

declare(strict_types=1);

namespace App\Inference;

use InvalidArgumentException;

final readonly class Usage
{
    public const int CHARACTERS_PER_TOKEN = 4;

    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedInputTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
        public bool $estimated = false,
    ) {
        foreach ([$inputTokens, $outputTokens, $cachedInputTokens, $cacheWriteTokens, $reasoningTokens] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }

    public static function estimate(int $inputCharacters, int $outputCharacters): self
    {
        return new self(
            inputTokens: (int) ceil($inputCharacters / self::CHARACTERS_PER_TOKEN),
            outputTokens: (int) ceil($outputCharacters / self::CHARACTERS_PER_TOKEN),
            estimated: true,
        );
    }
}
