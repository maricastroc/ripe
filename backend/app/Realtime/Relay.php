<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Jobs\PublishSignal;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Relay
{
    public function enabled(): bool
    {
        return Config::boolean('ripe.realtime.enabled');
    }

    public function send(Signal $signal): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            PublishSignal::dispatch($signal);
        } catch (Throwable $failure) {
            Log::warning('A realtime signal could not be queued.', ['event' => $signal->event, 'error' => $failure->getMessage()]);

            return false;
        }

        return true;
    }
}
