<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tasks;

use App\Actions\Tasks\HarvestTask;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class HarvestTaskController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(Task $task, HarvestTask $harvest): JsonResponse
    {
        $harvest->handle($task);

        return $this->task($task);
    }
}
