<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Agent\Limits;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Exceptions\TaskActionRejected;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Support\Facades\DB;

final class RenameTask
{
    use LocksTasks;

    public function handle(Task $task, string $title): Task
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $title));

        if ($clean === '') {
            throw new TaskActionRejected(__("The title can't be empty."));
        }

        if (mb_strlen($clean) > Limits::MAX_TITLE_CHARACTERS) {
            throw new TaskActionRejected(__('Keep the title under :max characters.', ['max' => Limits::MAX_TITLE_CHARACTERS]));
        }

        return DB::transaction(function () use ($task, $clean): Task {
            $task = $this->locked($task);

            if ($task->is_example) {
                throw new TaskActionRejected(__('Examples can only be read.'));
            }

            if ($task->title === $clean) {
                return $task;
            }

            $from = $task->title;
            $task->title = $clean;
            $task->save();

            TaskEvent::record($task, TaskEventType::TaskRenamed, ['from' => $from, 'to' => $clean], EventActor::Visitor);

            return $task;
        });
    }
}
