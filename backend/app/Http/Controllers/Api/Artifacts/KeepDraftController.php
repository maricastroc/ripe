<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Artifacts;

use App\Actions\Artifacts\CommitDraft;
use App\Http\Controllers\Api\RespondsWithTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\KeepDraftRequest;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;

final class KeepDraftController extends Controller
{
    use RespondsWithTasks;

    public function __invoke(KeepDraftRequest $request, Artifact $artifact, CommitDraft $commit): JsonResponse
    {
        $task = $artifact->owningTask();

        $commit->handle($artifact, $request->string('baseVersionId')->toString(), $request->integer('draftRevision'));

        return $this->task($task);
    }
}
