<?php

declare(strict_types=1);

namespace App\Inference\Streaming;

final readonly class ServerSentEvent
{
    public function __construct(
        public ?string $event,
        public string $data,
    ) {}
}
