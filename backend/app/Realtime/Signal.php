<?php

declare(strict_types=1);

namespace App\Realtime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

final readonly class Signal
{
    public const string TASK_CHANGED = 'task.changed';

    public const string OUTPUT_STREAMED = 'output.streamed';

    /**
     * @param  list<string>  $channels
     * @param  array<string, string|int|bool|null>  $payload
     */
    public function __construct(
        public array $channels,
        public string $event,
        public array $payload,
        public int $sentAtMs,
        public int $staleAfterSeconds,
    ) {}

    public static function taskChanged(TaskChange $change): self
    {
        $channels = ["private-task.{$change->taskId}"];

        if ($change->visitorId !== null) {
            $channels[] = "private-greenhouse.{$change->visitorId}";
        }

        return new self(
            $channels,
            self::TASK_CHANGED,
            ['taskId' => $change->taskId, 'version' => $change->version, 'deleted' => $change->deleted],
            CarbonImmutable::now()->getTimestampMs(),
            Config::integer('ripe.realtime.stale_change_seconds'),
        );
    }

    public static function outputStreamed(string $taskId, string $runId, string $attemptId, int $attempt, int $offset, string $delta): self
    {
        return new self(
            ["private-task.{$taskId}"],
            self::OUTPUT_STREAMED,
            ['runId' => $runId, 'attemptId' => $attemptId, 'attempt' => $attempt, 'offset' => $offset, 'delta' => $delta],
            CarbonImmutable::now()->getTimestampMs(),
            Config::integer('ripe.realtime.stale_output_seconds'),
        );
    }

    public function isStale(): bool
    {
        return CarbonImmutable::now()->getTimestampMs() - $this->sentAtMs > $this->staleAfterSeconds * 1000;
    }
}
