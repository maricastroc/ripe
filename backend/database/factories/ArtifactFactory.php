<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArtifactKind;
use App\Models\Artifact;
use App\Models\Step;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Artifact> */
class ArtifactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'step_id' => Step::factory(),
            'task_id' => static fn (array $attributes): string => Step::query()->whereKey($attributes['step_id'])->firstOrFail()->task_id,
            'kind' => ArtifactKind::Step,
            'title' => fake()->sentence(3),
        ];
    }

    public function forStep(Step $step): static
    {
        return $this->state([
            'step_id' => $step->id,
            'task_id' => $step->task_id,
            'title' => $step->title,
        ]);
    }

    public function review(?Task $task = null): static
    {
        return $this->state([
            'step_id' => null,
            'task_id' => $task->id ?? Task::factory()->reviewed(),
            'kind' => ArtifactKind::Review,
            'title' => 'Agent review',
        ]);
    }
}
