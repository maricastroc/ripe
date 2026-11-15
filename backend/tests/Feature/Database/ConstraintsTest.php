<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\AttemptOutcome;
use App\Enums\EventActor;
use App\Enums\RunKind;
use App\Enums\TaskEventType;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\InferenceBudgetDay;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Step;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function stepOf(Task $task, int $position = 0): Step
{
    return Step::factory()->for($task)->at($position)->create();
}

function versionOf(Artifact $artifact): ArtifactVersion
{
    return DB::transaction(fn (): ArtifactVersion => $artifact->addVersion('First draft.', ArtifactVersionSource::UserEdit, EventActor::Visitor));
}

dataset('constraint violations', [
    'a visitor token that is not a hash' => [fn () => Visitor::factory()->create(['token_hash' => str_repeat('z', 64)]), 'visitors_token_hash_check'],
    'two visitors with the same token' => [function (): void {
        $hash = Visitor::hashToken('same');
        Visitor::factory()->create(['token_hash' => $hash]);
        Visitor::factory()->create(['token_hash' => $hash]);
    }, 'visitors_token_hash_unique'],

    'an example owned by a visitor' => [fn () => Task::factory()->create(['is_example' => true]), 'tasks_owner_check'],
    'a task without owner that is not an example' => [fn () => Task::factory()->create(['visitor_id' => null]), 'tasks_owner_check'],
    'a task for an unknown visitor' => [fn () => Task::factory()->create(['visitor_id' => (string) Str::uuid7()]), 'tasks_visitor_id_foreign'],
    'a prompt that is too short' => [fn () => Task::factory()->create(['prompt' => ' ab ']), 'tasks_prompt_check'],
    'a prompt that is too long' => [fn () => Task::factory()->create(['prompt' => str_repeat('a', 2001)]), 'tasks_prompt_check'],
    'a blank title' => [fn () => Task::factory()->create(['title' => '   ']), 'tasks_title_check'],
    'an unknown task status' => [fn () => DB::table('tasks')->where('id', Task::factory()->create()->id)->update(['status' => 'rotten']), 'tasks_status_check'],
    'context that is not a list' => [fn () => DB::table('tasks')->where('id', Task::factory()->create()->id)->update(['context' => '{}']), 'tasks_context_check'],
    'assumptions that are not a list' => [fn () => DB::table('tasks')->where('id', Task::factory()->create()->id)->update(['assumptions' => '"x"']), 'tasks_assumptions_check'],
    'a negative version' => [fn () => DB::table('tasks')->where('id', Task::factory()->create()->id)->update(['version' => -1]), 'tasks_version_check'],
    'a plan before the interpretation' => [fn () => Task::factory()->create(['planned_at' => CarbonImmutable::now()]), 'tasks_milestones_check'],
    'a review before the plan' => [fn () => Task::factory()->interpreted()->create(['reviewed_at' => CarbonImmutable::now()]), 'tasks_milestones_check'],
    'an interpretation without deliverable' => [fn () => Task::factory()->interpreted()->create(['deliverable' => null]), 'tasks_interpretation_check'],
    'a ripening date before the review' => [fn () => DB::table('tasks')->where('id', Task::factory()->planned()->create()->id)->update(['ripe_at' => CarbonImmutable::now()]), 'tasks_milestones_check'],
    'a ripe task without review' => [fn () => DB::table('tasks')->where('id', Task::factory()->ripening()->create()->id)->update(['status' => 'ripe']), 'tasks_ripe_check'],
    'a ripe task without ripening date' => [fn () => DB::table('tasks')->where('id', Task::factory()->reviewed()->create()->id)->update(['status' => 'ripe']), 'tasks_ripe_check'],
    'a harvested task without harvest date' => [fn () => DB::table('tasks')->where('id', Task::factory()->ripe()->create()->id)->update(['status' => 'harvested']), 'tasks_harvest_check'],
    'a harvest date on a task that is not harvested' => [fn () => DB::table('tasks')->where('id', Task::factory()->ripe()->create()->id)->update(['harvested_at' => CarbonImmutable::now()]), 'tasks_harvest_check'],
    'a stalled task without reason' => [fn () => DB::table('tasks')->where('id', Task::factory()->ripening()->create()->id)->update(['status' => 'stalled']), 'tasks_stall_check'],

    'two steps in the same position' => [function (): void {
        $task = Task::factory()->planned()->create();
        stepOf($task);
        stepOf($task);
    }, 'steps_task_id_position_unique'],
    'a negative step position' => [fn () => stepOf(Task::factory()->planned()->create(), -1), 'steps_position_check'],
    'a done step without completion date' => [fn () => DB::table('steps')->where('id', Step::factory()->create()->id)->update(['status' => 'done']), 'steps_completion_check'],
    'an unknown step status' => [fn () => DB::table('steps')->where('id', Step::factory()->create()->id)->update(['status' => 'wilted']), 'steps_status_check'],

    'two questions in the same position' => [function (): void {
        $task = Task::factory()->interpreted()->create();
        Question::factory()->for($task)->create();
        Question::factory()->for($task)->create();
    }, 'questions_task_id_position_unique'],
    'an answered question without answer' => [fn () => DB::table('questions')->where('id', Question::factory()->create()->id)->update(['status' => 'answered', 'answer_revision' => 1, 'resolved_at' => CarbonImmutable::now()]), 'questions_answer_check'],
    'a skipped question without resolution date' => [fn () => DB::table('questions')->where('id', Question::factory()->create()->id)->update(['status' => 'skipped']), 'questions_resolution_check'],
    'an answered question at revision zero' => [fn () => DB::table('questions')->where('id', Question::factory()->create()->id)->update(['status' => 'answered', 'answer' => 'Yes', 'resolved_at' => CarbonImmutable::now()]), 'questions_revision_check'],

    'a step depending on itself' => [function (): void {
        $step = Step::factory()->create();
        DB::table('step_dependencies')->insert(['step_id' => $step->id, 'depends_on_step_id' => $step->id, 'task_id' => $step->task_id]);
    }, 'step_dependencies_self_check'],
    'a dependency on another task' => [function (): void {
        $step = stepOf(Task::factory()->planned()->create(), 1);
        $foreign = stepOf(Task::factory()->planned()->create());
        DB::table('step_dependencies')->insert(['step_id' => $step->id, 'depends_on_step_id' => $foreign->id, 'task_id' => $step->task_id]);
    }, 'step_dependencies_depends_on_step_id_task_id_foreign'],
    'a requirement marked as optional' => [function (): void {
        $task = Task::factory()->planned()->create();
        $question = Question::factory()->for($task)->create();
        DB::table('step_requirements')->insert(['step_id' => stepOf($task)->id, 'question_id' => $question->id, 'task_id' => $task->id, 'question_kind' => 'optional']);
    }, 'step_requirements_essential_check'],
    'a requirement on an optional question' => [function (): void {
        $task = Task::factory()->planned()->create();
        $question = Question::factory()->for($task)->create();
        DB::table('step_requirements')->insert(['step_id' => stepOf($task)->id, 'question_id' => $question->id, 'task_id' => $task->id]);
    }, 'step_requirements_question_id_task_id_question_kind_foreign'],
    'turning a required question optional' => [function (): void {
        $task = Task::factory()->planned()->create();
        $question = Question::factory()->for($task)->essential()->create();
        DB::transaction(fn () => stepOf($task)->requireAnswer($question));
        DB::table('questions')->where('id', $question->id)->update(['kind' => 'optional']);
    }, 'step_requirements_question_id_task_id_question_kind_foreign'],

    'a step artifact without step' => [fn () => Artifact::factory()->create(['step_id' => null, 'task_id' => Task::factory()->planned()->create()->id]), 'artifacts_step_link_check'],
    'two artifacts for one step' => [function (): void {
        $step = Step::factory()->create();
        Artifact::factory()->forStep($step)->create();
        Artifact::factory()->forStep($step)->create();
    }, 'artifacts_step_id_unique'],
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
    'a later version without parent' => [fn () => ArtifactVersion::factory()->create(['number' => 2]), 'artifact_versions_parent_check'],
    'a parent from another artifact' => [function (): void {
        $foreign = versionOf(Artifact::factory()->create());
        ArtifactVersion::factory()->create(['number' => 2, 'parent_version_id' => $foreign->id]);
    }, 'artifact_versions_parent_version_id_artifact_id_foreign'],
    'an agent version without run' => [fn () => ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate]), 'artifact_versions_run_link_check'],
    'a person edit linked to a run' => [fn () => ArtifactVersion::factory()->create(['run_id' => AgentRun::factory()->succeeded()->create()->id]), 'artifact_versions_run_link_check'],
    'two versions from one run' => [function (): void {
        $run = AgentRun::factory()->succeeded()->create();
        ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate, 'run_id' => $run->id]);
        ArtifactVersion::factory()->create(['source' => ArtifactVersionSource::Generate, 'run_id' => $run->id]);
    }, 'artifact_versions_run_id_unique'],
    'an instruction on a person edit' => [fn () => ArtifactVersion::factory()->create(['instruction' => 'Shorter.']), 'artifact_versions_instruction_check'],
    'a malformed content hash' => [fn () => ArtifactVersion::factory()->create(['content_hash' => str_repeat('g', 64)]), 'artifact_versions_content_hash_check'],
    'content longer than the editor allows' => [fn () => ArtifactVersion::factory()->create(['content' => str_repeat('a', 60001), 'content_hash' => str_repeat('a', 64)]), 'artifact_versions_content_check'],

    'a generation run without step' => [fn () => AgentRun::factory()->kind(RunKind::Generate)->create(), 'agent_runs_target_check'],
    'an interpretation run aimed at a step' => [fn () => AgentRun::factory()->create(['step_id' => Step::factory()->create()->id]), 'agent_runs_target_check'],
    'an instruction outside a rewrite' => [fn () => AgentRun::factory()->create(['instruction' => 'Shorter.']), 'agent_runs_instruction_check'],
    'a running run without lease' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['status' => 'running']), 'agent_runs_lease_check'],
    'a lease on a queued run' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['lease_token' => (string) Str::uuid()]), 'agent_runs_lease_check'],
    'a finished run without end date' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['status' => 'succeeded']), 'agent_runs_finish_check'],
    'a failed run without reason' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['status' => 'failed', 'finished_at' => CarbonImmutable::now()]), 'agent_runs_failure_check'],
    'more attempts than allowed' => [fn () => DB::table('agent_runs')->where('id', AgentRun::factory()->create()->id)->update(['attempt_count' => 4]), 'agent_runs_attempts_check'],
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
    'a rewrite based on another artifact' => [function (): void {
        $artifact = Artifact::factory()->create();
        $foreign = versionOf(Artifact::factory()->create());
        AgentRun::factory()->forArtifact($artifact, $foreign->id)->create();
    }, 'agent_runs_base_version_foreign'],

    'an outcome without end date' => [fn () => DB::table('run_attempts')->where('id', RunAttempt::factory()->create()->id)->update(['outcome' => AttemptOutcome::Failed->value]), 'run_attempts_finish_check'],
    'two open attempts of one run' => [function (): void {
        $run = AgentRun::factory()->running()->create();
        RunAttempt::factory()->for($run, 'run')->create();
        RunAttempt::factory()->for($run, 'run')->create(['number' => 2]);
    }, 'run_attempts_one_open_per_run_unique'],
    'two attempts with the same number' => [function (): void {
        $run = AgentRun::factory()->running()->create();
        RunAttempt::factory()->for($run, 'run')->finished()->create();
        RunAttempt::factory()->for($run, 'run')->create();
    }, 'run_attempts_run_id_number_unique'],
    'negative token counts' => [fn () => RunAttempt::factory()->create(['prompt_tokens' => -1]), 'run_attempts_metrics_check'],
    'negative reasoning tokens' => [fn () => RunAttempt::factory()->create(['reasoning_tokens' => -1]), 'run_attempts_usage_details_check'],
    'negative cached tokens' => [fn () => RunAttempt::factory()->create(['cached_prompt_tokens' => -1]), 'run_attempts_usage_details_check'],

    'a repeated event sequence' => [function (): void {
        $task = Task::factory()->create();
        DB::transaction(fn () => TaskEvent::record($task, TaskEventType::TaskPlanted, [], EventActor::Visitor));
        DB::table('task_events')->insert(['task_id' => $task->id, 'sequence' => 1, 'type' => 'task.planted', 'actor' => 'visitor', 'occurred_at' => CarbonImmutable::now()]);
    }, 'task_events_task_id_sequence_unique'],
    'a malformed event type' => [fn () => DB::table('task_events')->insert(['task_id' => Task::factory()->create()->id, 'sequence' => 1, 'type' => 'Planted', 'actor' => 'visitor', 'occurred_at' => CarbonImmutable::now()]), 'task_events_type_check'],
    'an event payload that is not an object' => [fn () => DB::table('task_events')->insert(['task_id' => Task::factory()->create()->id, 'sequence' => 1, 'type' => 'task.planted', 'actor' => 'visitor', 'payload' => '[]', 'occurred_at' => CarbonImmutable::now()]), 'task_events_payload_check'],
    'an attempt without its run' => [function (): void {
        $attempt = RunAttempt::factory()->create();
        DB::table('task_events')->insert(['task_id' => $attempt->run()->firstOrFail()->task_id, 'sequence' => 1, 'type' => 'run.started', 'actor' => 'agent', 'attempt_id' => $attempt->id, 'occurred_at' => CarbonImmutable::now()]);
    }, 'task_events_attempt_check'],
    'an event about a run of another task' => [fn () => DB::table('task_events')->insert(['task_id' => Task::factory()->create()->id, 'sequence' => 1, 'type' => 'run.started', 'actor' => 'agent', 'run_id' => AgentRun::factory()->create()->id, 'occurred_at' => CarbonImmutable::now()]), 'task_events_run_id_task_id_foreign'],

    'a negative budget' => [fn () => InferenceBudgetDay::factory()->create(['limit_microusd' => -1]), 'inference_budget_days_amounts_check'],
]);

it('rejects invalid data in the database itself', function (Closure $change, string $constraint): void {
    expectConstraintViolation($change, $constraint);
})->with('constraint violations');

it('lets a step be generated again once the previous run finished', function (): void {
    $step = Step::factory()->create();
    AgentRun::factory()->forStep($step)->succeeded()->create();

    expect(AgentRun::factory()->forStep($step)->create()->exists)->toBeTrue();
});

it('lets every task have its own review', function (): void {
    Artifact::factory()->review()->create();

    expect(Artifact::factory()->review()->create()->exists)->toBeTrue();
});

it('lets many person edits exist without a run', function (): void {
    $artifact = Artifact::factory()->create();

    DB::transaction(function () use ($artifact): void {
        $artifact->addVersion('One.', ArtifactVersionSource::UserEdit, EventActor::Visitor);
        $artifact->addVersion('Two.', ArtifactVersionSource::UserEdit, EventActor::Visitor);
    });

    expect($artifact->versions()->count())->toBe(2);
});
