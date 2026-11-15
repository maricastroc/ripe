<?php

declare(strict_types=1);

use App\Models\AgentRun;
use App\Models\Task;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $runId, $holdSeconds] = $argv;

DB::transaction(static function () use ($runId, $holdSeconds): void {
    $run = AgentRun::query()->findOrFail($runId);
    Task::query()->whereKey($run->task_id)->lockForUpdate()->firstOrFail();
    AgentRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);

    usleep((int) ((float) $holdSeconds * 1_000_000));

    AgentRun::query()->whereKey($runId)->update(['lease_expires_at' => CarbonImmutable::now()->subSecond()]);
});

$app->make(Sweeper::class)->expireLeases(CarbonImmutable::now());
