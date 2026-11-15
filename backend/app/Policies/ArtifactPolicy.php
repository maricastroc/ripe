<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Artifact;
use App\Models\Visitor;
use Illuminate\Auth\Access\Response;

final readonly class ArtifactPolicy
{
    public function __construct(private TaskPolicy $tasks) {}

    public function update(?Visitor $visitor, Artifact $artifact): Response
    {
        return $this->tasks->update($visitor, $artifact->owningTask());
    }
}
