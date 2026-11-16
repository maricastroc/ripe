<?php

declare(strict_types=1);

namespace App\Retention;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

final class Retention
{
    public static function expiresAt(Task $task): ?CarbonImmutable
    {
        if ($task->is_example || $task->created_at === null) {
            return null;
        }

        $created = CarbonImmutable::instance($task->created_at);
        $anchors = array_filter([
            $created,
            $task->return_at === null ? null : CarbonImmutable::instance($task->return_at),
            $task->relationLoaded('visitor') && $task->visitor?->last_seen_at !== null ? CarbonImmutable::instance($task->visitor->last_seen_at) : null,
        ]);
        $anchor = array_reduce($anchors, static fn (CarbonImmutable $latest, CarbonImmutable $candidate): CarbonImmutable => $candidate->greaterThan($latest) ? $candidate : $latest, $created);

        $kept = $anchor->addDays(self::daysAfterVisit());
        $ceiling = $created->addDays(self::maximumDays());

        return $kept->lessThan($ceiling) ? $kept : $ceiling;
    }

    /** @return Builder<Task> */
    public static function expired(CarbonImmutable $now): Builder
    {
        return Task::query()
            ->where('is_example', false)
            ->whereRaw(
                'least(
                    greatest(tasks.created_at, coalesce(tasks.return_at, tasks.created_at), coalesce((select last_seen_at from visitors where visitors.id = tasks.visitor_id), tasks.created_at)) + make_interval(days => ?),
                    tasks.created_at + make_interval(days => ?)
                ) <= ?',
                [self::daysAfterVisit(), self::maximumDays(), $now],
            );
    }

    public static function daysAfterVisit(): int
    {
        return Config::integer('ripe.retention.days_after_visit');
    }

    public static function maximumDays(): int
    {
        return Config::integer('ripe.retention.maximum_days');
    }
}
