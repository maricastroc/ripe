<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Realtime\Publisher;
use App\Realtime\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;

final class PublishSignal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly Signal $signal)
    {
        $this->onConnection(Config::string('ripe.realtime.queue_connection'));
        $this->onQueue(Config::string('ripe.realtime.queue'));
    }

    public function handle(Publisher $publisher): void
    {
        $publisher->publish($this->signal);
    }
}
