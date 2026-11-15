<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\Visitor;
use Illuminate\Auth\Access\Response;

final class TaskPolicy
{
    public function view(?Visitor $visitor, Task $task): Response
    {
        return $task->is_example || $this->owns($visitor, $task) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(?Visitor $visitor, Task $task): Response
    {
        if ($task->is_example) {
            return Response::deny('Examples can only be read.');
        }

        return $this->owns($visitor, $task) ? Response::allow() : Response::denyAsNotFound();
    }

    private function owns(?Visitor $visitor, Task $task): bool
    {
        return $visitor !== null && $task->visitor_id !== null && $task->visitor_id === $visitor->id;
    }
}
