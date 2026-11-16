<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Agent\Limits;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class PlantTask
{
    public function __construct(private readonly DeferTask $defer) {}

    public function handle(Visitor $visitor, string $prompt, bool $defer = true, ?CarbonImmutable $returnAt = null): Task
    {
        $clean = trim($prompt);

        if (mb_strlen($clean) < 3) {
            throw new TaskActionRejected(__('Write a little more about the task.'));
        }

        if (mb_strlen($clean) > Limits::MAX_PROMPT_CHARACTERS) {
            throw new TaskActionRejected(__('Keep the task under :max characters.', ['max' => Limits::MAX_PROMPT_CHARACTERS]));
        }

        return DB::transaction(function () use ($visitor, $clean, $defer, $returnAt): Task {
            Visitor::query()->whereKey($visitor->id)->lockForUpdate()->first();
            $cap = Config::integer('ripe.limits.plants_in_bed');

            if (Task::query()->where('visitor_id', $visitor->id)->where('status', '!=', TaskStatus::Harvested)->count() >= $cap) {
                throw new TaskActionRejected(__(':count plants are already growing here. Harvest or remove one before sowing another.', ['count' => $cap]));
            }

            $task = Task::query()->create([
                'visitor_id' => $visitor->id,
                'prompt' => $clean,
                'title' => self::provisionalTitle($clean),
                'return_at' => $returnAt,
            ]);

            TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor);

            if ($defer) {
                $this->defer->within($task, $returnAt);
            }

            return $task;
        });
    }

    public static function provisionalTitle(string $prompt): string
    {
        $first = (string) preg_replace('/\s+/u', ' ', strtok(trim($prompt), "\n") ?: trim($prompt));

        return mb_strlen($first) > 64 ? rtrim(mb_substr($first, 0, 61)).'…' : $first;
    }
}
