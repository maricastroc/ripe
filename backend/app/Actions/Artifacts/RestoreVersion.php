<?php

declare(strict_types=1);

namespace App\Actions\Artifacts;

use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Exceptions\TaskActionRejected;
use App\Exceptions\VersionConflict;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Support\Facades\DB;

final class RestoreVersion
{
    use LocksArtifacts;

    public function handle(Artifact $artifact, string $baseVersionId, ?string $versionId = null): ArtifactVersion
    {
        return DB::transaction(function () use ($artifact, $baseVersionId, $versionId): ArtifactVersion {
            [, $artifact, $draft] = $this->lockArtifact($artifact);
            $current = $this->currentVersionId($artifact);

            if ($baseVersionId !== $current) {
                throw VersionConflict::staleBase($current, $draft?->revision);
            }

            if ($draft !== null) {
                throw VersionConflict::draftPending($current, $draft->revision);
            }

            $targetId = $versionId ?? ArtifactVersion::query()->whereKey($current)->value('parent_version_id');
            $target = is_string($targetId) ? ArtifactVersion::query()->where('artifact_id', $artifact->id)->whereKey($targetId)->first() : null;

            if ($target === null) {
                throw new TaskActionRejected('There is no earlier version to go back to.');
            }

            if ($target->id === $current) {
                throw new TaskActionRejected('That is already the current version.');
            }

            return $artifact->addVersion($target->content, ArtifactVersionSource::Restore, EventActor::Visitor, inputs: ['restored_version_id' => $target->id]);
        });
    }
}
