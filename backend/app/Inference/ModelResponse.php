<?php

declare(strict_types=1);

namespace App\Inference;

use App\Enums\Inference\FinishReason;

final readonly class ModelResponse
{
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public Usage $usage,
        public string $driver,
        public string $model,
        public ?int $costMicrodollars,
        public ?string $requestId,
        public ?string $responseId,
        public ?int $timeToFirstTokenMs,
        public int $durationMs,
    ) {}
}
