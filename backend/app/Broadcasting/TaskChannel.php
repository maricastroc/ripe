<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Task;
use App\Models\Visitor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class TaskChannel
{
    public function join(Visitor $visitor, string $task): bool
    {
        if (! Str::isUuid($task)) {
            return false;
        }

        $found = Task::query()->find($task);

        return $found !== null && Gate::forUser($visitor)->inspect('view', $found)->allowed();
    }
}
