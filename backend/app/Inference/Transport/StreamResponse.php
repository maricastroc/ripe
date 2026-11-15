<?php

declare(strict_types=1);

namespace App\Inference\Transport;

final readonly class StreamResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $errorBody,
        public ?int $firstByteMs,
        public int $durationMs,
        public int $receivedBytes,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }
}
