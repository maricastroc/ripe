<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tasks;

use App\Actions\Tasks\RequestRevision;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class RefreshTaskController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(Task $task, RequestRevision $revisions): JsonResponse
    {
        $revisions->refreshWithAnswers($task);

        return $this->task($task);
    }
}
