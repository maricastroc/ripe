<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Artifacts;

use App\Actions\Artifacts\DiscardDraft;
use App\Actions\Artifacts\SaveDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscardDraftRequest;
use App\Http\Requests\SaveDraftRequest;
use App\Models\Artifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class DraftController extends Controller
{
    public function update(SaveDraftRequest $request, Artifact $artifact, SaveDraft $drafts): JsonResponse
    {
        $draft = $drafts->handle($artifact, $request->content(), $request->string('baseVersionId')->toString(), $request->draftRevision());

        return new JsonResponse([
            'draft' => $draft === null ? null : [
                'content' => $draft->content,
                'baseVersionId' => $draft->base_version_id,
                'revision' => $draft->revision,
                'conflicting' => false,
                'updatedAt' => $draft->updated_at?->toIso8601ZuluString('microsecond'),
            ],
        ]);
    }

    public function destroy(DiscardDraftRequest $request, Artifact $artifact, DiscardDraft $discard): Response
    {
        $discard->handle($artifact, $request->integer('draftRevision'));

        return response()->noContent();
    }
}
