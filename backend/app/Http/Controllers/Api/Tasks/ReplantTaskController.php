<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tasks;

use App\Actions\Tasks\ReplantTask;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class ReplantTaskController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(Task $task, ReplantTask $replant): JsonResponse
    {
        $replant->handle($task);

        return $this->task($task);
    }
}
