<?php

declare(strict_types=1);

namespace App\Growth;

use App\Enums\Stage;
use App\Enums\StepStatus;
use App\Models\Task;
use InvalidArgumentException;

final class GrowthCalculator
{
    public function forTask(Task $task): Growth
    {
        return $this->calculate(
            interpreted: $task->interpreted_at !== null,
            planned: $task->planned_at !== null,
            reviewed: $task->reviewed_at !== null,
            stepsDone: $task->steps()->where('status', StepStatus::Done)->count(),
            stepsTotal: $task->steps()->count(),
        );
    }

    public function calculate(bool $interpreted, bool $planned, bool $reviewed, int $stepsDone, int $stepsTotal): Growth
    {
        if ($stepsTotal < 0 || $stepsDone < 0 || $stepsDone > $stepsTotal) {
            throw new InvalidArgumentException("Cannot have {$stepsDone} of {$stepsTotal} steps done.");
        }

        $stage = Stage::Seed;

        if ($interpreted) {
            $stage = Stage::Sprout;
        }

        if ($planned && $stepsTotal > 0) {
            $stage = Stage::Leafing;
        }

        if ($stepsDone >= 1) {
            $stage = Stage::Blossom;
        }

        if ($stepsTotal > 0 && $stepsDone >= max(2, (int) ceil($stepsTotal / 2))) {
            $stage = Stage::Fruit;
        }

        if ($reviewed) {
            $stage = Stage::Ripe;
        }

        $maturity = ($interpreted ? 0.12 : 0.0)
            + ($planned ? 0.13 : 0.0)
            + ($stepsTotal > 0 ? 0.6 * $stepsDone / $stepsTotal : 0.0)
            + ($reviewed ? 0.15 : 0.0);

        return new Growth($stage, min(1.0, $maturity), $stepsDone, $stepsTotal);
    }
}
