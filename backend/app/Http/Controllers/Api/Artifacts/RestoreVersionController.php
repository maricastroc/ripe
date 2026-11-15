<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Artifacts;

use App\Actions\Artifacts\RestoreVersion;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\RestoreVersionRequest;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;

final class RestoreVersionController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(RestoreVersionRequest $request, Artifact $artifact, RestoreVersion $restore): JsonResponse
    {
        $task = $artifact->owningTask();

        $restore->handle($artifact, $request->string('baseVersionId')->toString(), $request->versionId());

        return $this->task($task);
    }
}
