<?php

declare(strict_types=1);

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $taskId, $count, $worker, $startAt] = $argv;

if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

for ($index = 0; $index < (int) $count; $index++) {
    DB::transaction(static function () use ($taskId, $worker, $index): void {
        $task = Task::query()->lockForUpdate()->findOrFail($taskId);
        usleep(random_int(0, 1500));
        TaskEvent::record($task, TaskEventType::RunScheduled, ['worker' => $worker, 'index' => $index], EventActor::System);
    });
}
