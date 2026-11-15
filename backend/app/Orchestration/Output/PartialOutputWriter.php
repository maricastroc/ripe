<?php

declare(strict_types=1);

namespace App\Orchestration\Output;

use App\Inference\Contracts\StreamObserver;
use App\Realtime\Relay;
use App\Realtime\Signal;

final class PartialOutputWriter implements StreamObserver
{
    private string $text = '';

    private float $flushedAt = 0.0;

    private bool $superseded = false;

    private int $relayed = 0;

    public function __construct(
        private readonly PartialOutput $output,
        private readonly Relay $relay,
        private readonly string $taskId,
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

            if (! $this->superseded) {
                $this->relay();
            }
        }
    }

    private function relay(): void
    {
        $length = mb_strlen($this->text);

        if ($length <= $this->relayed || ! $this->relay->enabled()) {
            return;
        }

        $signal = Signal::outputStreamed($this->taskId, $this->runId, $this->attemptId, $this->number, $this->relayed, mb_substr($this->text, $this->relayed));

        if ($this->relay->send($signal)) {
            $this->relayed = $length;
        }
    }

    public function isSuperseded(): bool
    {
        return $this->superseded;
    }
}
