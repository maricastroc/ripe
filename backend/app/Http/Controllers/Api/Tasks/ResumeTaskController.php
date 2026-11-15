<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tasks;

use App\Actions\Tasks\DeferTask;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class ResumeTaskController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(Task $task, DeferTask $defer): JsonResponse
    {
        $defer->handle($task);

        return $this->task($task);
    }
}
