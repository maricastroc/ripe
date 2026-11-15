<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

it('allocates gap-free sequences when separate processes write to the same task', function (): void {
    $task = Task::factory()->create();
    $workers = 4;
    $eventsPerWorker = 25;
    $startAt = (string) (microtime(true) + 1.5);

    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => config('database.connections.pgsql.database'),
    ];

    $results = Process::pool(function (Pool $pool) use ($task, $workers, $eventsPerWorker, $environment, $startAt): void {
        foreach (range(1, $workers) as $worker) {
            $pool->path(base_path())->env($environment)->command([
                PHP_BINARY, 'tests/Support/record-events.php', $task->id, (string) $eventsPerWorker, (string) $worker, $startAt,
            ]);
        }
    })->start()->wait();

    foreach ($results as $result) {
        expect($result->successful())->toBeTrue($result->errorOutput());
    }

    $total = $workers * $eventsPerWorker;

    expect(TaskEvent::query()->where('task_id', $task->id)->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, $total))
        ->and($task->fresh()?->version)->toBe($total)
        ->and(TaskEvent::query()->where('task_id', $task->id)->distinct()->count('payload->worker'))->toBe($workers);

    $writers = TaskEvent::query()->where('task_id', $task->id)->orderBy('sequence')->pluck('payload')->map(fn (array $payload): mixed => $payload['worker'])->all();
    $switches = count(array_filter(array_keys($writers), fn (int $i): bool => $i > 0 && $writers[$i] !== $writers[$i - 1]));

    expect($switches)->toBeGreaterThan($workers - 1);
});
