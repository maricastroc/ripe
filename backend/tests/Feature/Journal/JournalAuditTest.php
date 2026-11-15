<?php

declare(strict_types=1);

use App\Enums\ArtifactKind;
use App\Enums\AttemptOutcome;
use App\Enums\RunStatus;
use App\Journal\JournalAudit;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ripeTask(): Task
{
    fakeAgent([question('Who is it for?', 'optional')]);

    return ripen(plantTask());
}

/** @return list<string> */
function auditOf(Task $task): array
{
    return app(JournalAudit::class)->problems($task);
}

it('finds nothing wrong with a task the agent ripened', function (): void {
    expect(auditOf(ripeTask()))->toBe([]);
});

it('notices every kind of change made behind the journal', function (): void {
    $task = ripeTask();
    $events = $task->version;
    $artifact = Artifact::query()->where('task_id', $task->id)->where('kind', ArtifactKind::Step)->firstOrFail();
    $current = ArtifactVersion::query()->findOrFail($artifact->current_version_id);
    $attempt = RunAttempt::query()->whereIn('run_id', AgentRun::query()->where('task_id', $task->id)->select('id'))->firstOrFail();
    $run = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Succeeded)->whereKeyNot($attempt->run_id)->firstOrFail();
    $question = Question::query()->where('task_id', $task->id)->sole();
    $step = $task->steps()->firstOrFail();
    $version = (string) Str::uuid7();

    DB::table('artifact_versions')->insert([
        'id' => $version,
        'artifact_id' => $artifact->id,
        'number' => $current->number + 1,
        'content' => 'Edited outside the application.',
        'content_hash' => hash('sha256', 'Edited outside the application.'),
        'source' => 'user_edit',
        'parent_version_id' => $current->id,
    ]);
    DB::table('tasks')->where('id', $task->id)->update(['reviewed_at' => null, 'ripe_at' => null, 'status' => 'waiting', 'version' => $events + 1]);
    DB::table('run_attempts')->where('id', $attempt->id)->update(['outcome' => AttemptOutcome::TimedOut->value]);
    DB::table('agent_runs')->where('id', $run->id)->update(['status' => RunStatus::Failed->value, 'failure_reason' => 'Rewritten by hand.']);
    DB::table('questions')->where('id', $question->id)->update(['status' => 'skipped', 'resolved_at' => now()]);
    DB::table('steps')->where('id', $step->id)->update(['status' => 'ready', 'completed_at' => null]);

    expect(auditOf($task))->toContain(
        'the task is waiting but the journal says ripe',
        "artifact version {$version} is recorded 0 times",
        'the task version '.($events + 1)." does not match {$events} events",
        "attempt {$attempt->id} ended as timed_out without a matching event",
        "run {$run->id} is failed but has 0 run.failed events",
        "question {$question->id} is skipped but the journal says open",
        '2 steps are done but the journal records 3',
        'the plant is fruit but the journal says ripe',
    );
});
