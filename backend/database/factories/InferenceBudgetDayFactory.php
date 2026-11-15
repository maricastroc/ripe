<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InferenceBudgetDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InferenceBudgetDay> */
class InferenceBudgetDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day' => CarbonImmutable::now()->toDateString(),
            'limit_microusd' => 1_000_000,
        ];
    }
}
