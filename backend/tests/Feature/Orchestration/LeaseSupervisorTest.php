<?php

declare(strict_types=1);

use App\Exceptions\Inference\CallAborted;
use App\Orchestration\Execution\Claim;
use App\Orchestration\Execution\RunExecutor;
use App\Orchestration\Leases;
use App\Orchestration\LeaseSupervisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => Date::setTestNow(CarbonImmutable::now()));

/** @return array{0: Claim, 1: LeaseSupervisor} */
function supervisedClaim(): array
{
    fakeAgent();
    $claim = app(RunExecutor::class)->claim(interpretRun(plantTask())->id) ?? throw new RuntimeException('The run could not be claimed.');

    return [$claim, new LeaseSupervisor(app(Leases::class), $claim->run->id, $claim->token, $claim->expiresAt, 30.0, 5.0, 5.0)];
}

function silence(LeaseSupervisor $supervisor, int $seconds): void
{
    foreach (range(1, $seconds) as $second) {
        travelSeconds(1);
        $supervisor->tick();
    }
}

function abortReason(Closure $tick): ?string
{
    try {
        $tick();
    } catch (CallAborted $abort) {
        return $abort->reason;
    }

    return null;
}

function whileDatabaseIsUnreachable(Closure $during): void
{
    $connection = (string) config('database.default');
    $port = config("database.connections.{$connection}.port");
    config(["database.connections.{$connection}.port" => 1]);
    DB::purge($connection);

    try {
        $during();
    } finally {
        config(["database.connections.{$connection}.port" => $port]);
        DB::purge($connection);
    }
}

it('renews the lease on the clock while the model stays silent', function (): void {
    [$claim, $supervisor] = supervisedClaim();

    silence($supervisor, 120);

    expect($supervisor->renewals())->toBe(24)
        ->and($claim->run->refresh()->lease_expires_at?->equalTo(CarbonImmutable::now()->addSeconds(30)))->toBeTrue()
        ->and($claim->run->last_heartbeat_at?->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('notices a cancellation at the next renewal', function (): void {
    [$claim, $supervisor] = supervisedClaim();
    $claim->run->refresh()->requestCancellation(CarbonImmutable::now());

    silence($supervisor, 4);
    travelSeconds(1);

    expect(abortReason($supervisor->tick(...)))->toBe('cancelled');
});

it('gives up when the lease has passed to another attempt', function (): void {
    [$claim, $supervisor] = supervisedClaim();
    DB::table('agent_runs')->where('id', $claim->run->id)->update(['lease_token' => (string) Str::uuid7()]);
    travelSeconds(5);

    expect(abortReason($supervisor->tick(...)))->toBe('lease_lost');
});

it('never revives a lease that expired while the worker was frozen', function (): void {
    [, $supervisor] = supervisedClaim();
    travelSeconds(31);

    expect(abortReason($supervisor->tick(...)))->toBe('lease_lost');
});

it('fences itself when renewals cannot be confirmed before the safety margin', function (): void {
    [, $supervisor] = supervisedClaim();

    whileDatabaseIsUnreachable(function () use ($supervisor): void {
        silence($supervisor, 24);
        travelSeconds(1);

        expect(abortReason($supervisor->tick(...)))->toBe('lease_unconfirmed')
            ->and($supervisor->renewals())->toBe(0);
    });
});

it('carries on when the database comes back before the margin', function (): void {
    [$claim, $supervisor] = supervisedClaim();

    whileDatabaseIsUnreachable(static fn () => silence($supervisor, 20));
    silence($supervisor, 40);

    expect($supervisor->renewals())->toBe(8)
        ->and($claim->run->refresh()->lease_expires_at?->greaterThan(CarbonImmutable::now()->addSeconds(25)))->toBeTrue();
});
