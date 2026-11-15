<?php

declare(strict_types=1);

namespace App\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class WorkerHeartbeat
{
    private const string KEY = 'ripe:agent:heartbeat';

    private const int EVERY_SECONDS = 10;

    private const int ALIVE_FOR_SECONDS = 60;

    private ?int $lastBeat = null;

    public function beat(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->lastBeat !== null && $now - $this->lastBeat < self::EVERY_SECONDS) {
            return;
        }

        $this->lastBeat = $now;
        Cache::put(self::KEY, $now, self::ALIVE_FOR_SECONDS * 2);
    }

    public function isAlive(): bool
    {
        $last = Cache::get(self::KEY);

        return is_int($last) && CarbonImmutable::now()->getTimestamp() - $last <= self::ALIVE_FOR_SECONDS;
    }
}
