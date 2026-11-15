<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentStatusResource;
use App\Http\Resources\TaskSummaryResource;
use App\Models\Visitor;
use App\Orchestration\AgentStatusReader;
use App\Snapshots\TaskSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GardenController extends Controller
{
    public function show(Request $request, TaskSnapshots $snapshots, AgentStatusReader $agent): JsonResponse
    {
        $visitor = $request->user();
        $visitor = $visitor instanceof Visitor ? $visitor : null;

        return new JsonResponse([
            'tasks' => TaskSummaryResource::collection($snapshots->garden($visitor)),
            'agent' => new AgentStatusResource($agent->for($visitor)),
        ]);
    }
}
