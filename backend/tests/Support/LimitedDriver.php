<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inference\Contracts\CallSupervisor;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Contracts\QuotaLimited;
use App\Inference\Contracts\StreamObserver;
use App\Inference\DriverHealth;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;
use App\Inference\QuotaLimits;

final class LimitedDriver implements ModelDriver, QuotaLimited
{
    public int $calls = 0;

    public function __construct(
        private readonly FakeDriver $inner,
        private readonly QuotaLimits $limits,
        private readonly int $demand = 1000,
    ) {}

    public function name(): string
    {
        return 'limited';
    }

    public function model(): string
    {
        return 'limited-model';
    }

    public function health(): DriverHealth
    {
        return DriverHealth::available(['limited-model']);
    }

    public function isMetered(): bool
    {
        return false;
    }

    public function maximumCost(ModelRequest $request): int
    {
        return 0;
    }

    public function quotaScope(): string
    {
        return 'limited:limited-model';
    }

    public function quotaLimits(): QuotaLimits
    {
        return $this->limits;
    }

    public function tokenDemand(ModelRequest $request): int
    {
        return $this->demand;
    }

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse
    {
        $this->calls++;

        return $this->inner->generate($request, $supervisor, $observer);
    }
}
