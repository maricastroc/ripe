<?php

declare(strict_types=1);

namespace App\Inference;

use App\Enums\Inference\ReasoningEffort;
use InvalidArgumentException;

final readonly class ModelRequest
{
    /** @param list<Message> $messages */
    public function __construct(
        public string $instructions,
        public array $messages,
        public int $maxOutputTokens,
        public ?OutputSchema $schema = null,
        public ?float $temperature = null,
        public ?ReasoningEffort $reasoningEffort = null,
        public ?string $safetyIdentifier = null,
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('A model request needs at least one message.');
        }

        if ($maxOutputTokens < 16) {
            throw new InvalidArgumentException('A model request must allow at least 16 output tokens.');
        }

        if ($temperature !== null && ($temperature < 0.0 || $temperature > 2.0)) {
            throw new InvalidArgumentException('The temperature must be between 0 and 2.');
        }

        if ($safetyIdentifier !== null && ($safetyIdentifier === '' || strlen($safetyIdentifier) > 128)) {
            throw new InvalidArgumentException('A safety identifier must have between 1 and 128 characters.');
        }
    }

    public function isStructured(): bool
    {
        return $this->schema !== null;
    }

    public function inputCharacters(): int
    {
        $total = mb_strlen($this->instructions);

        foreach ($this->messages as $message) {
            $total += mb_strlen($message->content);
        }

        return $total;
    }
}
