<?php

declare(strict_types=1);

namespace App\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class AgentHalt
{
    private const string KEY = 'ripe:agent:halt';

    public function halt(string $reason, CarbonImmutable $now): void
    {
        Cache::forever(self::KEY, ['reason' => $reason, 'since' => $now->toIso8601String()]);
    }

    public function isHalted(): bool
    {
        return $this->reason() !== null;
    }

    public function reason(): ?string
    {
        $halt = Cache::get(self::KEY);

        return is_array($halt) && is_string($halt['reason'] ?? null) ? $halt['reason'] : null;
    }

    public function lift(): void
    {
        Cache::forget(self::KEY);
    }
}
