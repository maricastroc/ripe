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

it('notices a status that changed behind the journal', function (): void {
    $task = ripeTask();
    DB::table('tasks')->where('id', $task->id)->update(['status' => 'waiting']);

    expect(auditOf($task))->toContain('the task is waiting but the journal says ripe');
});

it('notices a version that no event records', function (): void {
    $task = ripeTask();
    $artifact = Artifact::query()->where('task_id', $task->id)->where('kind', ArtifactKind::Step)->firstOrFail();
    $current = ArtifactVersion::query()->findOrFail($artifact->current_version_id);
    $id = (string) Str::uuid7();

    DB::table('artifact_versions')->insert([
        'id' => $id,
        'artifact_id' => $artifact->id,
        'number' => $current->number + 1,
        'content' => 'Edited outside the application.',
        'content_hash' => hash('sha256', 'Edited outside the application.'),
        'source' => 'user_edit',
        'parent_version_id' => $current->id,
    ]);

    expect(auditOf($task))->toContain("artifact version {$id} is recorded 0 times");
});

it('notices a sequence that no longer matches the task version', function (): void {
    $task = ripeTask();
    $events = $task->version;
    DB::table('tasks')->where('id', $task->id)->increment('version');

    expect(auditOf($task))->toBe(['the task version '.($events + 1)." does not match {$events} events"]);
});

it('notices an attempt whose outcome the journal never saw', function (): void {
    $task = ripeTask();
    $attempt = RunAttempt::query()->whereIn('run_id', AgentRun::query()->where('task_id', $task->id)->select('id'))->firstOrFail();
    DB::table('run_attempts')->where('id', $attempt->id)->update(['outcome' => AttemptOutcome::TimedOut->value]);

    expect(auditOf($task))->toContain("attempt {$attempt->id} ended as timed_out without a matching event");
});

it('notices a run that ended differently from what the journal says', function (): void {
    $task = ripeTask();
    $run = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Succeeded)->firstOrFail();
    DB::table('agent_runs')->where('id', $run->id)->update(['status' => RunStatus::Failed->value, 'failure_reason' => 'Rewritten by hand.']);

    expect(auditOf($task))->toContain("run {$run->id} is failed but has 0 run.failed events");
});

it('notices a question answered behind the journal', function (): void {
    $task = ripeTask();
    $question = Question::query()->where('task_id', $task->id)->sole();
    DB::table('questions')->where('id', $question->id)->update(['status' => 'skipped', 'resolved_at' => now()]);

    expect(auditOf($task))->toContain("question {$question->id} is skipped but the journal says open");
});

it('notices a step completed behind the journal', function (): void {
    $task = ripeTask();
    $step = $task->steps()->firstOrFail();
    DB::table('steps')->where('id', $step->id)->update(['status' => 'ready', 'completed_at' => null]);

    expect(auditOf($task))->toContain('2 steps are done but the journal records 3');
});

it('notices growth that the journal never recorded', function (): void {
    $task = ripeTask();
    DB::table('tasks')->where('id', $task->id)->update(['reviewed_at' => null, 'ripe_at' => null, 'status' => 'waiting']);

    expect(auditOf($task))->toContain('the plant is fruit but the journal says ripe');
});
