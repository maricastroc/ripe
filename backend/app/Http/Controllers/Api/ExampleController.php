<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskSummaryResource;
use App\Snapshots\TaskSnapshots;
use Illuminate\Http\JsonResponse;

final class ExampleController extends Controller
{
    public function index(TaskSnapshots $snapshots): JsonResponse
    {
        return new JsonResponse(['examples' => TaskSummaryResource::collection($snapshots->examples())]);
    }
}
