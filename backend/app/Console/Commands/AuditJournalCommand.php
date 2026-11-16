<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Journal\JournalAudit;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('ripe:audit {task? : Audit only this task}')]
#[Description('Check that every task journal agrees with the persisted state')]
final class AuditJournalCommand extends Command
{
    public function handle(JournalAudit $audit): int
    {
        $id = $this->argument('task');
        $query = Task::query();

        if (is_string($id)) {
            if (! Str::isUuid($id) || ! Task::query()->whereKey($id)->exists()) {
                $this->error("No task {$id}.");

                return self::FAILURE;
            }

            $query->whereKey($id);
        }

        $checked = 0;
        $failing = 0;

        foreach ($query->lazyById(200, 'id') as $task) {
            $problems = $this->problems($audit, $task);
            $checked++;

            if ($problems === []) {
                continue;
            }

            $failing++;
            $this->line("{$task->id} ({$task->status->value})");

            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }
        }

        $this->line("{$checked} ".Str::plural('task', $checked)." checked, {$failing} with problems.");

        return $failing === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function problems(JournalAudit $audit, Task $task): array
    {
        if (DB::transactionLevel() > 0) {
            return $audit->problems($task);
        }

        return DB::transaction(static function () use ($audit, $task): array {
            DB::statement('set transaction isolation level repeatable read, read only');

            return $audit->problems($task);
        });
    }
}
