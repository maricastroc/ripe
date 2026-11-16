<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimelineResource;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;

final class TaskTimelineController extends Controller
{
    public function __invoke(Task $task): JsonResponse
    {
        $task->load([
            'runs' => static fn (Relation $query): Relation => $query->orderBy('created_at')->orderBy('id'),
            'runs.attempts',
            'runs.step' => static fn (Relation $query): Relation => $query->select(['id', 'title']),
            'runs.artifact' => static fn (Relation $query): Relation => $query->select(['id', 'title']),
        ]);

        return new JsonResponse(['timeline' => new TimelineResource($task)]);
    }
}
