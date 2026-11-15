<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Question> */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory()->interpreted(),
            'position' => 0,
            'text' => fake()->sentence().'?',
            'rationale' => fake()->sentence(),
            'kind' => QuestionKind::Optional,
            'status' => QuestionStatus::Open,
        ];
    }

    public function at(int $position): static
    {
        return $this->state(['position' => $position]);
    }

    public function essential(): static
    {
        return $this->state(['kind' => QuestionKind::Essential]);
    }

    public function answered(string $answer = 'An answer.'): static
    {
        return $this->state([
            'status' => QuestionStatus::Answered,
            'answer' => $answer,
            'answer_revision' => 1,
            'resolved_at' => CarbonImmutable::now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state([
            'status' => QuestionStatus::Skipped,
            'resolved_at' => CarbonImmutable::now(),
        ]);
    }
}
