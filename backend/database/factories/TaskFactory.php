<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Deliverable;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'is_example' => false,
            'prompt' => fake()->sentence(12),
            'title' => fake()->sentence(3),
            'status' => TaskStatus::Dormant,
        ];
    }

    public function example(): static
    {
        return $this->state(['visitor_id' => null, 'is_example' => true]);
    }

    public function ripening(): static
    {
        return $this->state([
            'status' => TaskStatus::Ripening,
            'deferred_at' => CarbonImmutable::now(),
        ]);
    }

    public function interpreted(Deliverable $deliverable = Deliverable::Document): static
    {
        return $this->ripening()->state([
            'language' => 'en',
            'deliverable' => $deliverable,
            'fruit' => $deliverable->fruit(),
            'outcome' => fake()->sentence(),
            'interpreted_at' => CarbonImmutable::now(),
        ]);
    }

    public function planned(): static
    {
        return $this->interpreted()->state(['planned_at' => CarbonImmutable::now()]);
    }

    public function reviewed(): static
    {
        return $this->planned()->state(['reviewed_at' => CarbonImmutable::now()]);
    }

    public function ripe(): static
    {
        return $this->reviewed()->state([
            'status' => TaskStatus::Ripe,
            'ripe_at' => CarbonImmutable::now(),
        ]);
    }

    public function stalled(string $reason = 'The model kept failing.'): static
    {
        return $this->ripening()->state([
            'status' => TaskStatus::Stalled,
            'stall_reason' => $reason,
        ]);
    }

    public function harvested(): static
    {
        return $this->ripe()->state([
            'status' => TaskStatus::Harvested,
            'harvested_at' => CarbonImmutable::now(),
        ]);
    }
}
