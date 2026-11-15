<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OutputFormat;
use App\Enums\StepStatus;
use App\Models\Step;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Step> */
class StepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory()->planned(),
            'position' => 0,
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'output_format' => OutputFormat::Draft,
            'status' => StepStatus::Ready,
        ];
    }

    public function at(int $position): static
    {
        return $this->state(['position' => $position]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => StepStatus::Blocked]);
    }

    public function done(): static
    {
        return $this->state([
            'status' => StepStatus::Done,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }
}
