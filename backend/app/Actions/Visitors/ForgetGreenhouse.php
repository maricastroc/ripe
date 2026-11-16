<?php

declare(strict_types=1);

namespace App\Actions\Visitors;

use App\Actions\Tasks\ForgetTask;
use App\Enums\EventActor;
use App\Models\Task;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;

final readonly class ForgetGreenhouse
{
    public function __construct(private ForgetTask $forget) {}

    public function handle(Visitor $visitor): int
    {
        return DB::transaction(function () use ($visitor): int {
            $locked = Visitor::query()->whereKey($visitor->id)->lockForUpdate()->first();

            if ($locked === null) {
                return 0;
            }

            $tasks = Task::query()->where('visitor_id', $locked->id)->orderBy('id')->lockForUpdate()->get();

            foreach ($tasks as $task) {
                $this->forget->handle($task, EventActor::Visitor);
            }

            $locked->delete();

            return $tasks->count();
        });
    }
}
