<?php

declare(strict_types=1);

namespace App\Orchestration\Output;

use App\Inference\Contracts\StreamObserver;

final class PartialOutputWriter implements StreamObserver
{
    private string $text = '';

    private float $flushedAt = 0.0;

    private bool $superseded = false;

    public function __construct(
        private readonly PartialOutput $output,
        private readonly string $runId,
        private readonly string $attemptId,
        private readonly int $number,
        private readonly float $flushInterval,
        private readonly int $maxCharacters,
    ) {}

    public function onDelta(string $delta): void
    {
        if ($this->superseded || mb_strlen($this->text) >= $this->maxCharacters) {
            return;
        }

        $this->text = mb_substr($this->text.$delta, 0, $this->maxCharacters);
        $now = hrtime(true) / 1e9;

        if ($now - $this->flushedAt >= $this->flushInterval) {
            $this->flushedAt = $now;
            $this->superseded = ! $this->output->write($this->runId, $this->attemptId, $this->number, $this->text);
        }
    }

    public function isSuperseded(): bool
    {
        return $this->superseded;
    }
}
