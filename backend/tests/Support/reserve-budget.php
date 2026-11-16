<?php

declare(strict_types=1);

use App\Models\Task;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\Budget\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $taskId, $amount, $startAt] = $argv;

if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

$outcome = DB::transaction(static function () use ($app, $taskId, $amount): string {
    $result = $app->make(InferenceBudget::class)->reserve(Task::query()->findOrFail($taskId), (int) $amount, CarbonImmutable::now());

    return $result instanceof Reservation ? 'reserved' : "short:{$result->scope->value}";
});

echo $outcome;
