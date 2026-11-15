<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Models\Task;

final readonly class TaskChange
{
    public function __construct(
        public string $taskId,
        public ?string $visitorId,
        public int $version,
        public bool $deleted,
    ) {}

    public static function of(Task $task, bool $deleted = false): self
    {
        return new self($task->id, $task->visitor_id, $task->version, $deleted);
    }

    public function merge(self $later): self
    {
        return new self(
            $this->taskId,
            $this->visitorId ?? $later->visitorId,
            max($this->version, $later->version),
            $this->deleted || $later->deleted,
        );
    }
}
