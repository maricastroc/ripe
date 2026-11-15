<?php

declare(strict_types=1);

namespace App\Inference\Transport;

final readonly class StreamRequest
{
    /**
     * @param  non-empty-string  $method
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
    ) {}
}
