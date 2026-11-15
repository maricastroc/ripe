<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\Inference\CallAborted;
use App\Inference\Contracts\CallSupervisor;

final class RecordingSupervisor implements CallSupervisor
{
    /** @var list<float> */
    public array $ticks = [];

    private readonly float $startedAt;

    public function __construct(private readonly ?float $abortAfterSeconds = null, private readonly ?int $abortAfterTicks = null)
    {
        $this->startedAt = microtime(true);
    }

    public function tick(): void
    {
        $this->ticks[] = microtime(true) - $this->startedAt;

        if ($this->abortAfterSeconds !== null && end($this->ticks) >= $this->abortAfterSeconds) {
            throw new CallAborted('cancelled', 'The run was cancelled.');
        }

        if ($this->abortAfterTicks !== null && count($this->ticks) >= $this->abortAfterTicks) {
            throw new CallAborted('lease_lost', 'The lease was lost.');
        }
    }

    public function longestGapBetween(float $from, float $to): float
    {
        $inside = array_values(array_filter($this->ticks, static fn (float $tick): bool => $tick >= $from && $tick <= $to));
        $points = [$from, ...$inside, $to];
        $longest = 0.0;

        for ($index = 1; $index < count($points); $index++) {
            $longest = max($longest, $points[$index] - $points[$index - 1]);
        }

        return $longest;
    }
}
