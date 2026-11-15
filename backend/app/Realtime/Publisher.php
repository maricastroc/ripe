<?php

declare(strict_types=1);

namespace App\Realtime;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Publisher
{
    private ?CarbonImmutable $pausedUntil = null;

    private int $dropped = 0;

    public function __construct(private readonly Factory $broadcasts) {}

    public function publish(Signal $signal): bool
    {
        if ($signal->isStale()) {
            return false;
        }

        if ($this->pausedUntil !== null && CarbonImmutable::now()->lessThan($this->pausedUntil)) {
            $this->dropped++;

            return false;
        }

        try {
            $this->broadcasts->connection()->broadcast($signal->channels, $signal->event, $signal->payload);
        } catch (Throwable $failure) {
            if ($this->pausedUntil === null) {
                Log::warning('Realtime publishing paused: Reverb did not accept a signal.', ['event' => $signal->event, 'error' => $failure->getMessage()]);
            }

            $this->pausedUntil = CarbonImmutable::now()->addSeconds(Config::integer('ripe.realtime.pause_seconds'));

            return false;
        }

        if ($this->pausedUntil !== null) {
            Log::info('Realtime publishing resumed.', ['dropped' => $this->dropped]);
            $this->pausedUntil = null;
            $this->dropped = 0;
        }

        return true;
    }
}
