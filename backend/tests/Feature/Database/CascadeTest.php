<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;

function plantFullTask(Visitor $visitor): Task
{
    $task = Task::factory()->for($visitor)->planned()->create();

    DB::transaction(function () use ($task): void {
        $first = Step::factory()->for($task)->at(0)->done()->create();
        $second = Step::factory()->for($task)->at(1)->create();
        $question = Question::factory()->for($task)->essential()->create();
        $second->dependOn($first);
        $second->requireAnswer($question);

        $run = AgentRun::factory()->forStep($first)->succeeded()->create();
        $attempt = RunAttempt::factory()->for($run, 'run')->finished()->create();
        $artifact = Artifact::factory()->forStep($first)->create();
        $version = $artifact->addVersion('Piece.', ArtifactVersionSource::Generate, EventActor::Agent, $run);
        $artifact->addVersion('Edited piece.', ArtifactVersionSource::UserEdit, EventActor::Visitor);
        AgentRun::factory()->forArtifact($artifact, $version->id)->create();
        TaskEvent::record($task, TaskEventType::RunSucceeded, [], EventActor::Agent, $run, $attempt);
    });

    return $task;
}

function rowsBelongingTo(string $taskId): array
{
    return collect(['steps', 'questions', 'artifacts', 'agent_runs', 'task_events'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('task_id', $taskId)->count()])
        ->put('step_dependencies', DB::table('step_dependencies')->where('task_id', $taskId)->count())
        ->put('step_requirements', DB::table('step_requirements')->where('task_id', $taskId)->count())
        ->put('artifact_versions', DB::table('artifact_versions')->join('artifacts', 'artifacts.id', '=', 'artifact_versions.artifact_id')->where('artifacts.task_id', $taskId)->count())
        ->put('run_attempts', DB::table('run_attempts')->join('agent_runs', 'agent_runs.id', '=', 'run_attempts.run_id')->where('agent_runs.task_id', $taskId)->count())
        ->all();
}

it('removes everything below a task when the task is deleted', function (): void {
    $visitor = Visitor::factory()->create();
    $task = plantFullTask($visitor);
    $kept = plantFullTask($visitor);

    $task->delete();

    expect(array_sum(rowsBelongingTo($task->id)))->toBe(0)
        ->and(array_filter(rowsBelongingTo($kept->id)))->toHaveCount(9);
});

it('removes a whole greenhouse when its visitor is deleted', function (): void {
    $visitor = Visitor::factory()->create();
    $task = plantFullTask($visitor);
    $neighbour = plantFullTask(Visitor::factory()->create());

    $visitor->delete();

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse()
        ->and(array_sum(rowsBelongingTo($task->id)))->toBe(0)
        ->and(array_filter(rowsBelongingTo($neighbour->id)))->toHaveCount(9);
});
