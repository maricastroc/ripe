<?php

declare(strict_types=1);

namespace App\Actions\Visitors;

use App\Actions\Tasks\ForgetTask;
use App\Enums\EventActor;
use App\Models\Visitor;
use App\Retention\Retention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final readonly class PruneGardens
{
    public function __construct(private ForgetTask $forget) {}

    /** @return array{tasks: int, visitors: int} */
    public function handle(CarbonImmutable $now): array
    {
        $tasks = 0;

        foreach (Retention::expired($now)->orderBy('id')->pluck('id') as $id) {
            $tasks += DB::transaction(function () use ($id, $now): int {
                $task = Retention::expired($now)->whereKey($id)->lockForUpdate()->first();

                if ($task === null) {
                    return 0;
                }

                $this->forget->handle($task, EventActor::System);

                return 1;
            });
        }

        $visitors = Visitor::query()
            ->where('last_seen_at', '<', $now->subDays(Config::integer('ripe.retention.idle_visitor_days')))
            ->whereDoesntHave('tasks')
            ->toBase()
            ->delete();

        return ['tasks' => $tasks, 'visitors' => $visitors];
    }
}
