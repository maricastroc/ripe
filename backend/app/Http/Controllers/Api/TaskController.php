<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\PlantTask;
use App\Actions\Tasks\RenameTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlantTaskRequest;
use App\Http\Requests\RenameTaskRequest;
use App\Http\Resources\AgentStatusResource;
use App\Http\Resources\TaskResource;
use App\Http\Visitors\VisitorCookie;
use App\Http\Visitors\VisitorResolver;
use App\Models\Task;
use App\Models\Visitor;
use App\Orchestration\AgentStatusReader;
use App\Snapshots\TaskSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class TaskController extends Controller
{
    use RespondsWithTasks;

    public function store(PlantTaskRequest $request, PlantTask $plant, VisitorResolver $visitors, VisitorCookie $cookie): JsonResponse
    {
        $visitor = $request->user();
        $token = null;

        [$visitor, $task] = DB::transaction(function () use ($visitor, $visitors, $plant, $request, &$token): array {
            if (! $visitor instanceof Visitor) {
                [$visitor, $token] = $visitors->create();
            }

            return [$visitor, $plant->handle($visitor, $request->prompt(), $request->shouldDefer(), $request->returnAt())];
        });

        Auth::guard('visitor')->setUser($visitor);
        $response = $this->task($task, 201);

        if ($token !== null) {
            $response->headers->setCookie($cookie->issue($token));
        }

        return $response;
    }

    public function show(Request $request, Task $task, TaskSnapshots $snapshots, AgentStatusReader $agent): JsonResponse
    {
        $visitor = $request->user();

        return new JsonResponse([
            'task' => new TaskResource($snapshots->detail($task)),
            'agent' => new AgentStatusResource($agent->for($visitor instanceof Visitor ? $visitor : null)),
        ]);
    }

    public function update(RenameTaskRequest $request, Task $task, RenameTask $rename): JsonResponse
    {
        return $this->task($rename->handle($task, $request->string('title')->toString()));
    }

    public function destroy(Task $task, DeleteTask $delete): Response
    {
        $delete->handle($task);

        return response()->noContent();
    }
}
