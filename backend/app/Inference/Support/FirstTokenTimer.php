<?php

declare(strict_types=1);

namespace App\Inference\Support;

final class FirstTokenTimer
{
    private readonly int $startedAt;

    private ?int $firstTokenAt = null;

    public function __construct()
    {
        $this->startedAt = self::now();
    }

    public function tokenArrived(): void
    {
        $this->firstTokenAt ??= self::now();
    }

    public function milliseconds(): ?int
    {
        return $this->firstTokenAt === null ? null : intdiv($this->firstTokenAt - $this->startedAt, 1_000_000);
    }

    private static function now(): int
    {
        $time = hrtime(true);

        return is_int($time) ? $time : (int) round($time);
    }
}
