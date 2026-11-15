<?php

declare(strict_types=1);

use App\Orchestration\TaskOrchestrator;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $taskId, $startAt] = $argv;

if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

$app->make(TaskOrchestrator::class)->reconcileTask($taskId);
