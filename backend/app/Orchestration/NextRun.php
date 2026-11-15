<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\RunKind;
use App\Enums\RunOrigin;
use App\Models\Artifact;
use App\Models\Step;

final readonly class NextRun
{
    private function __construct(
        public RunKind $kind,
        public RunOrigin $origin = RunOrigin::Orchestrator,
        public ?Step $step = null,
        public ?Artifact $artifact = null,
        public ?string $baseVersionId = null,
        public ?string $instruction = null,
    ) {}

    public static function interpret(): self
    {
        return new self(RunKind::Interpret);
    }

    public static function plan(): self
    {
        return new self(RunKind::Plan);
    }

    public static function generate(Step $step): self
    {
        return new self(RunKind::Generate, step: $step);
    }

    public static function review(): self
    {
        return new self(RunKind::Review);
    }

    public static function finalise(Artifact $artifact, string $baseVersionId): self
    {
        return new self(RunKind::Revise, artifact: $artifact, baseVersionId: $baseVersionId);
    }

    public static function revision(Artifact $artifact, string $baseVersionId, ?string $instruction): self
    {
        return new self(RunKind::Revise, RunOrigin::Visitor, artifact: $artifact, baseVersionId: $baseVersionId, instruction: $instruction);
    }
}
