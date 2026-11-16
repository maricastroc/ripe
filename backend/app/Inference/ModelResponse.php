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

    public function costing(int $microdollars): self
    {
        return new self($this->text, $this->finishReason, $this->usage, $this->driver, $this->model, $microdollars, $this->requestId, $this->responseId, $this->timeToFirstTokenMs, $this->durationMs);
    }
}
