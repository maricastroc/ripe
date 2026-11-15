<?php

declare(strict_types=1);

namespace App\Actions\Artifacts;

use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Exceptions\VersionConflict;
use App\Models\Artifact;
use App\Models\ArtifactDraft;
use App\Models\ArtifactVersion;
use Illuminate\Support\Facades\DB;

final class CommitDraft
{
    use LocksArtifacts;

    public function handle(Artifact $artifact, string $baseVersionId, int $draftRevision): ArtifactVersion
    {
        return DB::transaction(function () use ($artifact, $baseVersionId, $draftRevision): ArtifactVersion {
            [, $artifact, $draft] = $this->lockArtifact($artifact);
            $current = $this->currentVersionId($artifact);

            if ($draft === null || $draft->revision !== $draftRevision) {
                throw VersionConflict::draftMoved($current, $draft?->revision);
            }

            if ($baseVersionId !== $current || $draft->base_version_id !== $current) {
                throw VersionConflict::staleBase($current, $draft->revision);
            }

            return self::consolidate($artifact, $draft);
        });
    }

    public static function consolidate(Artifact $artifact, ArtifactDraft $draft): ArtifactVersion
    {
        $version = $artifact->addVersion($draft->content, ArtifactVersionSource::UserEdit, EventActor::Visitor);
        $draft->delete();

        return $version;
    }
}
