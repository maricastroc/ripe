<?php

declare(strict_types=1);

namespace App\Inference\Pricing;

use App\Inference\Usage;

final class CostCalculator
{
    public function microdollars(Usage $usage, ModelPrice $price): int
    {
        $cached = min($usage->cachedInputTokens, $usage->inputTokens);
        $written = min($usage->cacheWriteTokens, $usage->inputTokens - $cached);
        $uncached = $usage->inputTokens - $cached - $written;

        return $this->perMillion(
            $uncached * $price->inputPerMillion
            + $cached * $price->cachedInputPerMillion
            + $written * $price->cacheWritePerMillion
            + $usage->outputTokens * $price->outputPerMillion,
        );
    }

    public function ceiling(int $inputTokens, int $outputTokens, ModelPrice $price): int
    {
        $inputRate = max($price->inputPerMillion, $price->cacheWritePerMillion);

        return $this->perMillion($inputTokens * $inputRate + $outputTokens * $price->outputPerMillion);
    }

    private function perMillion(int $scaled): int
    {
        return intdiv($scaled + 999_999, 1_000_000);
    }
}
