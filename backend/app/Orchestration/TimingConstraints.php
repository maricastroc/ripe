<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Inference\Transport\Timeouts;
use Illuminate\Support\Facades\Config;

final readonly class TimingConstraints
{
    public function __construct(private AgentSettings $settings) {}

    /** @return list<string> */
    public function violations(): array
    {
        $ttl = $this->settings->leaseTtl();
        $renew = $this->settings->renewInterval();
        $margin = $this->settings->safetyMargin();
        $worker = $this->settings->workerTimeout();
        $retryAfter = Config::integer("queue.connections.{$this->settings->queueConnection()}.retry_after");
        $redispatch = $this->settings->redispatchAfterSeconds();
        $violations = [];

        if ($renew > $ttl / 3) {
            $violations[] = "The lease is renewed every {$renew} s, more than a third of its {$ttl} s lifetime.";
        }

        if ($margin + $renew >= $ttl) {
            $violations[] = "A {$margin} s safety margin and a {$renew} s renewal interval leave no time to confirm a {$ttl} s lease.";
        }

        foreach ($this->callTimeouts() as $driver => $total) {
            if ($total + $ttl >= $worker) {
                $violations[] = "The {$driver} driver allows calls of up to {$total} s, which with a {$ttl} s lease do not fit in the {$worker} s worker timeout.";
            }
        }

        if ($worker >= $retryAfter) {
            $violations[] = "The {$worker} s worker timeout must be shorter than the queue's {$retryAfter} s retry_after.";
        }

        if ($redispatch <= $retryAfter) {
            $violations[] = "Lost jobs are re-dispatched after {$redispatch} s, before the queue itself would redeliver them at {$retryAfter} s.";
        }

        return $violations;
    }

    /** @return array<string, float> */
    public function callTimeouts(): array
    {
        $timeouts = [];

        foreach (Config::array('inference.drivers') as $driver => $config) {
            if (is_array($config) && is_array($config['timeouts'] ?? null)) {
                $timeouts[(string) $driver] = Timeouts::fromConfig($config['timeouts'])->total;
            }
        }

        return $timeouts;
    }
}
