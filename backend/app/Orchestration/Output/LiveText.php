<?php

declare(strict_types=1);

namespace App\Orchestration\Output;

use App\Models\AgentRun;

final readonly class LiveText
{
    public static function of(AgentRun $run): ?self
    {
        return app(PartialOutput::class)->current($run);
    }

    public function __construct(
        public string $attemptId,
        public int $attemptNumber,
        public string $text,
    ) {}

    public function length(): int
    {
        return mb_strlen($this->text);
    }
}
