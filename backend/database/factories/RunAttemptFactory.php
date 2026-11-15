<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttemptOutcome;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RunAttempt> */
class RunAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => AgentRun::factory()->running(),
            'number' => 1,
            'lease_token' => (string) Str::uuid(),
            'started_at' => CarbonImmutable::now(),
        ];
    }

    public function finished(AttemptOutcome $outcome = AttemptOutcome::Succeeded): static
    {
        return $this->state([
            'outcome' => $outcome,
            'finished_at' => CarbonImmutable::now(),
        ]);
    }
}
