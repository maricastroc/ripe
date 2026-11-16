<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Models\Task;
use App\Models\Visitor;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class TaskSnapshots
{
    public const int NOTES = 80;

    /** @return Collection<int, Task> */
    public function garden(?Visitor $visitor): Collection
    {
        if ($visitor === null) {
            return new Collection;
        }

        return Task::query()
            ->where('visitor_id', $visitor->id)
            ->with($this->summaryRelations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, Task> */
    public function examples(): Collection
    {
        return Task::query()
            ->where('is_example', true)
            ->whereHas('exampleRecord')
            ->with($this->summaryRelations())
            ->orderBy('created_at')
            ->get();
    }

    public function detail(Task $task): Task
    {
        return Task::query()->with([
            ...$this->summaryRelations(),
            'steps.dependencies:id,status',
            'steps.requirements:id',
            'questions' => static fn (Builder $query): Builder => $query->orderBy('position'),
            'artifacts' => static fn (Builder $query): Builder => $query->orderByRaw("kind = 'review'")->orderBy('created_at')->orderBy('id'),
            'artifacts.currentVersion',
            'artifacts.draft',
            'artifacts.versions:id,artifact_id,number,source,instruction,created_at',
            'events' => static fn (Builder $query): Builder => $query->reorder()->orderByDesc('sequence')->limit(self::NOTES),
        ])->findOrFail($task->id);
    }

    public static function numberOf(Task $task): int
    {
        return Task::query()
            ->where('is_example', $task->is_example)
            ->where('visitor_id', $task->visitor_id)
            ->where('created_at', '<=', $task->created_at)
            ->count();
    }

    /** @return array<string, (Closure(Builder): Builder)|string> */
    private function summaryRelations(): array
    {
        return [
            'exampleRecord' => static fn (Builder $query): Builder => $query,
            'steps' => static fn (Builder $query): Builder => $query->orderBy('position'),
            'steps.artifact' => static fn (Builder $query): Builder => $query->select(['id', 'step_id']),
            'questions' => static fn (Builder $query): Builder => $query->orderBy('position'),
            'activeRuns.latestAttempt' => static fn (Builder $query): Builder => $query,
            'activeRuns.step' => static fn (Builder $query): Builder => $query->select(['id', 'title']),
            'activeRuns.artifact' => static fn (Builder $query): Builder => $query->select(['id', 'title']),
        ];
    }
}
