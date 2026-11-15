<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Artifacts;

use App\Actions\Tasks\RequestRevision;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviseArtifactRequest;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;

final class ReviseArtifactController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(ReviseArtifactRequest $request, Artifact $artifact, RequestRevision $revisions): JsonResponse
    {
        $task = $artifact->owningTask();

        $revisions->handle($artifact, $request->string('instruction')->toString());

        return $this->task($task);
    }
}
