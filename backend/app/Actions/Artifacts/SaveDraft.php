<?php

declare(strict_types=1);

namespace App\Actions\Artifacts;

use App\Exceptions\VersionConflict;
use App\Models\Artifact;
use App\Models\ArtifactDraft;
use App\Models\ArtifactVersion;
use Illuminate\Support\Facades\DB;

final class SaveDraft
{
    use LocksArtifacts;

    public function handle(Artifact $artifact, string $content, string $baseVersionId, ?int $draftRevision = null): ?ArtifactDraft
    {
        return DB::transaction(function () use ($artifact, $content, $baseVersionId, $draftRevision): ?ArtifactDraft {
            [, $artifact, $draft] = $this->lockArtifact($artifact);
            $current = $this->currentVersionId($artifact);

            if ($baseVersionId !== $current) {
                throw VersionConflict::staleBase($current, $draft?->revision);
            }

            if ($draft?->revision !== $draftRevision) {
                throw VersionConflict::draftMoved($current, $draft?->revision);
            }

            if ($content === ArtifactVersion::query()->whereKey($current)->value('content')) {
                $draft?->delete();

                return null;
            }

            $draft ??= new ArtifactDraft(['artifact_id' => $artifact->id, 'revision' => 0]);
            $draft->fill(['base_version_id' => $current, 'content' => $content, 'revision' => $draft->revision + 1]);
            $draft->save();

            return $draft;
        });
    }
}
