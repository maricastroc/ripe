<?php

declare(strict_types=1);

namespace App\Actions\Artifacts;

use App\Exceptions\TaskActionRejected;
use App\Models\Artifact;
use App\Models\ArtifactDraft;
use App\Models\Task;
use App\Support\Transactions;

trait LocksArtifacts
{
    /** @return array{Task, Artifact, ?ArtifactDraft} */
    private function lockArtifact(Artifact $artifact): array
    {
        Transactions::require('Changing a piece');

        $task = Task::query()->whereKey($artifact->task_id)->lockForUpdate()->firstOrFail();
        $locked = Artifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('task', $task);
        $draft = ArtifactDraft::query()->whereKey($artifact->id)->lockForUpdate()->first();

        if ($task->is_example) {
            throw new TaskActionRejected(__('Examples can only be read.'));
        }

        return [$task, $locked, $draft];
    }

    private function currentVersionId(Artifact $artifact): string
    {
        return $artifact->current_version_id ?? throw new TaskActionRejected(__('There is nothing written here yet.'));
    }
}
