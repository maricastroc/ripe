<?php

declare(strict_types=1);

namespace App\Actions\Artifacts;

use App\Exceptions\VersionConflict;
use App\Models\Artifact;
use Illuminate\Support\Facades\DB;

final class DiscardDraft
{
    use LocksArtifacts;

    public function handle(Artifact $artifact, int $draftRevision): void
    {
        DB::transaction(function () use ($artifact, $draftRevision): void {
            [, $artifact, $draft] = $this->lockArtifact($artifact);

            if ($draft === null) {
                return;
            }

            if ($draft->revision !== $draftRevision) {
                throw VersionConflict::draftMoved($artifact->current_version_id, $draft->revision);
            }

            $draft->delete();
        });
    }
}
