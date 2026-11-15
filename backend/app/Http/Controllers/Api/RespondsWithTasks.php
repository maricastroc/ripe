<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Snapshots\TaskSnapshots;
use Illuminate\Http\JsonResponse;

trait RespondsWithTasks
{
    private function task(Task $task, int $status = 200): JsonResponse
    {
        return new JsonResponse(['task' => new TaskResource(app(TaskSnapshots::class)->detail($task))], $status);
    }
}
