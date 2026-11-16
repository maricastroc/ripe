<?php

declare(strict_types=1);

use App\Inference\QuotaLimits;
use App\Orchestration\Quota\QuotaGate;
use App\Orchestration\Quota\QuotaTicket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $requestsPerMinute, $tokensPerMinute, $demand, $startAt] = $argv;

if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

$limits = new QuotaLimits((int) $requestsPerMinute, 1000, (int) $tokensPerMinute, 1_000_000, 100);

echo DB::transaction(static fn (): string => $app->make(QuotaGate::class)->admit('race:model', $limits, (int) $demand, CarbonImmutable::now()) instanceof QuotaTicket ? 'admitted' : 'refused');
