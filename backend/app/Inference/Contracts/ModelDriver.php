<?php

declare(strict_types=1);

namespace App\Inference\Contracts;

use App\Inference\DriverHealth;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;

interface ModelDriver
{
    public function name(): string;

    public function model(): string;

    public function health(): DriverHealth;

    public function isMetered(): bool;

    public function maximumCost(ModelRequest $request): ?int;

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse;
}
