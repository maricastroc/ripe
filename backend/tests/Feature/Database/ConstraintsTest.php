<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\RunKind;
use App\Enums\TaskEventType;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function stepOf(Task $task, int $position = 0): Step
{
    return Step::factory()->for($task)->at($position)->create();
}

function versionOf(Artifact $artifact): ArtifactVersion
{
    return DB::transaction(fn (): ArtifactVersion => $artifact->addVersion('First draft.', ArtifactVersionSource::UserEdit, EventActor::Visitor));
}

dataset('constraint violations', [
    'two visitors with the same token' => [function (): void {
        $hash = Visitor::hashToken('same');
        Visitor::factory()->create(['token_hash' => $hash]);
        Visitor::factory()->create(['token_hash' => $hash]);
    }, 'visitors_token_hash_unique'],
    'a task without owner that is not an example' => [fn () => Task::factory()->create(['visitor_id' => null]), 'tasks_owner_check'],
    'a plan before the interpretation' => [fn () => Task::factory()->create(['planned_at' => CarbonImmutable::now()]), 'tasks_milestones_check'],
    'a ripe task without review' => [fn () => DB::table('tasks')->where('id', Task::factory()->ripening()->create()->id)->update(['status' => 'ripe']), 'tasks_ripe_check'],
    'a dependency on another task' => [function (): void {
        $step = stepOf(Task::factory()->planned()->create(), 1);
        $foreign = stepOf(Task::factory()->planned()->create());
        DB::table('step_dependencies')->insert(['step_id' => $step->id, 'depends_on_step_id' => $foreign->id, 'task_id' => $step->task_id]);
    }, 'step_dependencies_depends_on_step_id_task_id_foreign'],
    'a requirement on an optional question' => [function (): void {
        $task = Task::factory()->planned()->create();
        $question = Question::factory()->for($task)->create();
        DB::table('step_requirements')->insert(['step_id' => stepOf($task)->id, 'question_id' => $question->id, 'task_id' => $task->id]);
    }, 'step_requirements_question_id_task_id_question_kind_foreign'],
    'two reviews for one task' => [function (): void {
        $task = Task::factory()->reviewed()->create();
        Artifact::factory()->review($task)->create();
        Artifact::factory()->review($task)->create();
    }, 'artifacts_one_review_per_task_unique'],
    'an artifact for a step of another task' => [fn () => Artifact::factory()->create(['step_id' => Step::factory()->create()->id, 'task_id' => Task::factory()->planned()->create()->id]), 'artifacts_step_id_task_id_foreign'],
    'a current version of another artifact' => [function (): void {
        $artifact = Artifact::factory()->create();
        $foreign = versionOf(Artifact::factory()->create());
        DB::table('artifacts')->where('id', $artifact->id)->update(['current_version_id' => $foreign->id]);
    }, 'artifacts_current_version_foreign'],
    'two versions with the same number' => [function (): void {
        $artifact = Artifact::factory()->create();
        versionOf($artifact);
        ArtifactVersion::factory()->for($artifact)->create();
    }, 'artifact_versions_artifact_id_number_unique'],
    'a parent from another artifact' => [function (): void {
        $foreign = versionOf(Artifact::factory()->create());
        ArtifactVersion::factory()->create(['number' => 2, 'parent_version_id' => $foreign->id]);
    }, 'artifact_versions_parent_version_id_artifact_id_foreign'],
    'an agent version without run' => [fn () => ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate]), 'artifact_versions_run_link_check'],
    'two versions from one run' => [function (): void {
        $run = AgentRun::factory()->succeeded()->create();
        ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate, 'run_id' => $run->id]);
        ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate, 'run_id' => $run->id]);
    }, 'artifact_versions_run_id_unique'],
    'a running run without lease' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['status' => 'running']), 'agent_runs_lease_check'],
    'two running runs in one task' => [function (): void {
        $task = Task::factory()->planned()->create();
        AgentRun::factory()->for($task)->running()->create();
        AgentRun::factory()->for($task)->running()->kind(RunKind::Generate)->create(['step_id' => stepOf($task)->id]);
    }, 'agent_runs_one_running_per_task_unique'],
    'two active interpretations of one task' => [function (): void {
        $task = Task::factory()->ripening()->create();
        AgentRun::factory()->for($task)->create();
        AgentRun::factory()->for($task)->create();
    }, 'agent_runs_one_active_task_run_unique'],
    'two active generations of one step' => [function (): void {
        $step = Step::factory()->create();
        AgentRun::factory()->forStep($step)->create();
        AgentRun::factory()->forStep($step)->create();
    }, 'agent_runs_one_active_step_run_unique'],
    'two active rewrites of one artifact' => [function (): void {
        $artifact = Artifact::factory()->create();
        $version = versionOf($artifact);
        AgentRun::factory()->forArtifact($artifact, $version->id)->create();
        AgentRun::factory()->forArtifact($artifact, $version->id)->create();
    }, 'agent_runs_one_active_artifact_run_unique'],
    'a run for a step of another task' => [fn () => AgentRun::factory()->kind(RunKind::Generate)->create(['step_id' => Step::factory()->create()->id]), 'agent_runs_step_id_task_id_foreign'],
    'two open attempts of one run' => [function (): void {
        $run = AgentRun::factory()->running()->create();
        RunAttempt::factory()->for($run, 'run')->create();
        RunAttempt::factory()->for($run, 'run')->create(['number' => 2]);
    }, 'run_attempts_one_open_per_run_unique'],
    'a repeated event sequence' => [function (): void {
        $task = Task::factory()->create();
        DB::transaction(fn () => TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor));
        DB::table('task_events')->insert(['task_id' => $task->id, 'sequence' => 1, 'type' => 'task.planted', 'actor' => 'visitor', 'occurred_at' => CarbonImmutable::now()]);
    }, 'task_events_task_id_sequence_unique'],
    'an event about a run of another task' => [fn () => DB::table('task_events')->insert(['task_id' => Task::factory()->create()->id, 'sequence' => 1, 'type' => 'run.started', 'actor' => 'agent', 'run_id' => AgentRun::factory()->create()->id, 'occurred_at' => CarbonImmutable::now()]), 'task_events_run_id_task_id_foreign'],
]);

it('rejects invalid data in the database itself', function (Closure $change, string $constraint): void {
    expectConstraintViolation($change, $constraint);
})->with('constraint violations');

it('lets a step be generated again once the previous run finished', function (): void {
    $step = Step::factory()->create();
    AgentRun::factory()->forStep($step)->succeeded()->create();

    expect(AgentRun::factory()->forStep($step)->create()->exists)->toBeTrue();
});
