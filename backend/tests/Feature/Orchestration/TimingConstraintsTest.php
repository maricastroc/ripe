<?php

declare(strict_types=1);

use App\Orchestration\TimingConstraints;
use Illuminate\Support\Facades\Process;

it('ships with leases, calls, the worker timeout and redelivery in a safe order', function (): void {
    $result = Process::path(base_path())->env([
        'APP_ENV' => 'testing',
        'RIPE_LEASE_RENEW_INTERVAL' => false,
        'RIPE_LEASE_TTL' => false,
        'RIPE_LEASE_SAFETY_MARGIN' => false,
        'RIPE_WORKER_TIMEOUT' => false,
        'RIPE_AGENT_RETRY_AFTER' => false,
    ])->run([PHP_BINARY, 'artisan', 'ripe:timing']);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and($result->output())->toContain('ordered safely');
});

it('catches a renewal interval too long for the lease', function (): void {
    config(['ripe.lease.renew_interval' => 11.0]);

    expect(app(TimingConstraints::class)->violations())->toContain('The lease is renewed every 11 s, more than a third of its 30 s lifetime.');
});

it('catches a safety margin that leaves no time to confirm a renewal', function (): void {
    config(['ripe.lease.renew_interval' => 10.0, 'ripe.lease.safety_margin' => 20.0]);

    expect(app(TimingConstraints::class)->violations())->toContain('A 20 s safety margin and a 10 s renewal interval leave no time to confirm a 30 s lease.');
});

it('catches a model call that could outlive the worker', function (): void {
    config(['inference.drivers.openai.timeouts.total' => 340]);

    expect(app(TimingConstraints::class)->violations())->toBe(['The openai driver allows calls of up to 340 s, which with a 30 s lease do not fit in the 360 s worker timeout.']);
});

it('catches a worker that could outlive the queue redelivery', function (): void {
    config(['queue.connections.agent.retry_after' => 360]);

    expect(app(TimingConstraints::class)->violations())->toContain("The 360 s worker timeout must be shorter than the queue's 360 s retry_after.");
});

it('catches a re-dispatch that would race the queue redelivery', function (): void {
    config(['ripe.agent.redispatch_after_seconds' => 300]);

    expect(app(TimingConstraints::class)->violations())->toBe(['Lost jobs are re-dispatched after 300 s, before the queue itself would redeliver them at 420 s.']);
});
