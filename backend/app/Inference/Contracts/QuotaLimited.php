<?php

declare(strict_types=1);

namespace App\Inference\Contracts;

use App\Inference\ModelRequest;
use App\Inference\QuotaLimits;

interface QuotaLimited
{
    public function quotaScope(): string;

    public function quotaLimits(): QuotaLimits;

    public function tokenDemand(ModelRequest $request): int;
}
