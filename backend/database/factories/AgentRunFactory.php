<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Step;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentRun> */
class AgentRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory()->ripening(),
            'kind' => RunKind::Interpret,
            'status' => RunStatus::Queued,
            'available_at' => CarbonImmutable::now(),
            'max_attempts' => 3,
        ];
    }

    public function kind(RunKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    public function forStep(Step $step): static
    {
        return $this->state([
            'task_id' => $step->task_id,
            'kind' => RunKind::Generate,
            'step_id' => $step->id,
        ]);
    }

    public function forArtifact(Artifact $artifact, string $baseVersionId): static
    {
        return $this->state([
            'task_id' => $artifact->task_id,
            'kind' => RunKind::Revise,
            'artifact_id' => $artifact->id,
            'base_version_id' => $baseVersionId,
        ]);
    }

    public function running(): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => RunStatus::Running,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => $now->addSeconds(30),
            'last_heartbeat_at' => $now,
            'started_at' => $now,
            'attempt_count' => 1,
        ]);
    }

    public function succeeded(): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => RunStatus::Succeeded,
            'started_at' => $now,
            'finished_at' => $now,
            'attempt_count' => 1,
        ]);
    }
}
