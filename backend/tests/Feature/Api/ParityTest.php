<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\QuestionStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Task;
use Carbon\CarbonImmutable;

const SUMMARY_KEYS = ['id', 'title', 'prompt', 'status', 'fruit', 'isExample', 'version', 'returnAt', 'createdAt', 'ripeAt', 'harvestedAt', 'stallReason', 'growth' => ['stage', 'maturity', 'stepsDone', 'stepsTotal'], 'leaves', 'openQuestions', 'essentialOpen', 'activity'];

const DETAIL_KEYS = [...SUMMARY_KEYS, 'number', 'language', 'deliverable', 'outcome', 'context', 'assumptions', 'steps', 'questions', 'artifacts', 'events', 'runsUsed', 'runBudget'];

const AGENT_KEYS = ['hosting', 'mode', 'message', 'driver', 'model', 'online', 'models', 'settings', 'current', 'elsewhere', 'queued'];

/** @return array{string, Task} */
function ripeApiTask(array $questions = []): array
{
    fakeAgent($questions);
    [, $token] = visitor();
    $task = ripen(apiTask($token));

    return [$token, $task];
}

it('lists the visitor garden with the agent status', function (): void {
    fakeAgent();
    [, $token] = visitor();
    $older = apiTask($token, 'Plan the December trip with the whole family.');
    $newer = apiTask($token);

    api($token)->getJson('/api/garden')
        ->assertOk()
        ->assertJsonStructure(['tasks' => ['*' => SUMMARY_KEYS], 'agent' => AGENT_KEYS])
        ->assertJsonPath('tasks.0.id', $newer->id)
        ->assertJsonPath('tasks.1.id', $older->id)
        ->assertJsonPath('tasks.0.activity.kind', 'interpret');
});

it('plants a task and answers with its snapshot', function (): void {
    fakeAgent();
    $response = api()->postJson('/api/tasks', ['prompt' => '  Write a short thank-you note to the neighbour.  ', 'returnAt' => CarbonImmutable::now()->addDay()->toIso8601String()]);

    $response->assertCreated()
        ->assertJsonStructure(['task' => DETAIL_KEYS])
        ->assertJsonPath('task.prompt', 'Write a short thank-you note to the neighbour.')
        ->assertJsonPath('task.status', 'ripening')
        ->assertJsonPath('task.number', 1)
        ->assertJsonPath('task.activity.kind', 'interpret');

    expectJournalConsistent(Task::query()->sole());
});

it('shows one task with the agent status', function (): void {
    [$token, $task] = ripeApiTask([question('Who is it for?', 'optional')]);

    api($token)->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonStructure(['task' => DETAIL_KEYS, 'agent' => AGENT_KEYS])
        ->assertJsonPath('task.status', 'ripe')
        ->assertJsonPath('task.growth.stage', 'ripe')
        ->assertJsonPath('task.questions.0.kind', 'optional')
        ->assertJsonPath('task.steps.1.dependsOn.0', $task->steps()->orderBy('position')->first()?->id)
        ->assertJsonPath('task.artifacts.3.kind', 'review')
        ->assertJsonPath('task.events.0.message', 'Ripe. Everything is written and reviewed.');
});

it('defers a dormant task until a chosen moment', function (): void {
    fakeAgent();
    [, $token] = visitor();
    $id = api($token)->postJson('/api/tasks', ['prompt' => 'Sort out the spare room before the holidays.', 'defer' => false])->json('task.id');
    $returnAt = CarbonImmutable::now()->addHours(16)->startOfSecond();

    api($token)->postJson("/api/tasks/{$id}/defer", ['returnAt' => $returnAt->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('task.status', 'ripening')
        ->assertJsonPath('task.returnAt', $returnAt->toIso8601ZuluString('microsecond'));
});

it('pauses and resumes a task', function (): void {
    fakeAgent();
    [, $token] = visitor();
    $task = apiTask($token);

    api($token)->postJson("/api/tasks/{$task->id}/pause")->assertOk()->assertJsonPath('task.status', 'dormant')->assertJsonPath('task.activity', null);

    expect(interpretRun($task)->status)->toBe(RunStatus::Cancelled)
        ->and(drainAgentQueue())->toBe(1)
        ->and(RunAttempt::query()->count())->toBe(0);

    api($token)->postJson("/api/tasks/{$task->id}/resume")->assertOk()->assertJsonPath('task.status', 'ripening');

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('harvests a ripe task and replants it', function (): void {
    [$token, $task] = ripeApiTask();

    api($token)->postJson("/api/tasks/{$task->id}/harvest")->assertOk()->assertJsonPath('task.status', 'harvested');
    api($token)->postJson("/api/tasks/{$task->id}/replant")->assertOk()->assertJsonPath('task.status', 'ripe');
    expectJournalConsistent($task);
});

it('refreshes the pieces that still have gaps', function (): void {
    [$token, $task] = ripeApiTask();

    api($token)->postJson("/api/tasks/{$task->id}/refresh")->assertOk()->assertJsonPath('task.status', 'ripening');
    api($token)->postJson("/api/tasks/{$task->id}/refresh")->assertConflict()->assertJsonPath('message', 'None of the pieces has gaps left to fill.');

    expect(AgentRun::query()->where('kind', RunKind::Revise)->count())->toBe(3)
        ->and(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('renames a task', function (): void {
    [$token, $task] = ripeApiTask();

    api($token)->patchJson("/api/tasks/{$task->id}", ['title' => '  Thank-you   note  '])
        ->assertOk()
        ->assertJsonPath('task.title', 'Thank-you note')
        ->assertJsonPath('task.events.0.message', 'Renamed it “Thank-you note”.');
    expectJournalConsistent($task);
});

it('deletes a task with everything in it', function (): void {
    [$token, $task] = ripeApiTask();

    api($token)->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(Task::query()->count())->toBe(0)
        ->and(ArtifactVersion::query()->count())->toBe(0)
        ->and(AgentRun::query()->count())->toBe(0);
});

it('answers, skips and reopens questions', function (): void {
    [$token, $task] = ripeApiTask([question('Which day works?'), question('Any allergies?', 'optional')]);
    [$essential, $optional] = Question::query()->orderBy('position')->get()->all();

    api($token)->postJson("/api/questions/{$optional->id}/skip")->assertOk()->assertJsonPath('task.questions.1.status', 'skipped');
    api($token)->postJson("/api/questions/{$optional->id}/reopen")->assertOk()->assertJsonPath('task.questions.1.status', 'open');
    api($token)->postJson("/api/questions/{$essential->id}/answer", ['answer' => 'Saturday morning.'])
        ->assertOk()
        ->assertJsonPath('task.questions.0.status', 'answered')
        ->assertJsonPath('task.questions.0.answer', 'Saturday morning.')
        ->assertJsonPath('task.status', 'ripening');

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe)
        ->and($essential->refresh()->status)->toBe(QuestionStatus::Answered);
    expectJournalConsistent($task);
});

it('edits a piece through a draft and keeps it as a new version', function (): void {
    [$token, $task] = ripeApiTask();
    $piece = firstPiece($task);
    $base = (string) $piece->current_version_id;

    $draft = api($token)->putJson("/api/artifacts/{$piece->id}/draft", ['content' => 'My own words.', 'baseVersionId' => $base, 'draftRevision' => null])
        ->assertOk()
        ->assertJsonPath('draft.revision', 1)
        ->assertJsonPath('draft.baseVersionId', $base);

    api($token)->getJson("/api/tasks/{$task->id}")->assertJsonPath('task.artifacts.0.draft.content', 'My own words.')->assertJsonPath('task.artifacts.0.content', $piece->currentVersion?->content);

    api($token)->postJson("/api/artifacts/{$piece->id}/draft/keep", ['baseVersionId' => $base, 'draftRevision' => $draft->json('draft.revision')])
        ->assertOk()
        ->assertJsonPath('task.artifacts.0.content', 'My own words.')
        ->assertJsonPath('task.artifacts.0.editedByUser', true)
        ->assertJsonPath('task.artifacts.0.version.source', 'user_edit')
        ->assertJsonPath('task.artifacts.0.draft', null)
        ->assertJsonCount(2, 'task.artifacts.0.versions');
    expectJournalConsistent($task);
});

it('discards a draft', function (): void {
    [$token, $task] = ripeApiTask();
    $piece = firstPiece($task);
    api($token)->putJson("/api/artifacts/{$piece->id}/draft", ['content' => 'Never mind.', 'baseVersionId' => $piece->current_version_id, 'draftRevision' => null]);

    api($token)->deleteJson("/api/artifacts/{$piece->id}/draft", ['draftRevision' => 1])->assertNoContent();
    api($token)->getJson("/api/tasks/{$task->id}")->assertJsonPath('task.artifacts.0.draft', null);
});

it('goes back to the previous version of a piece', function (): void {
    [$token, $task] = ripeApiTask();
    $piece = firstPiece($task);
    $first = (string) $piece->current_version_id;
    api($token)->putJson("/api/artifacts/{$piece->id}/draft", ['content' => 'Second.', 'baseVersionId' => $first, 'draftRevision' => null]);
    $second = api($token)->postJson("/api/artifacts/{$piece->id}/draft/keep", ['baseVersionId' => $first, 'draftRevision' => 1])->json('task.artifacts.0.version.id');

    api($token)->postJson("/api/artifacts/{$piece->id}/restore", ['baseVersionId' => $second])
        ->assertOk()
        ->assertJsonPath('task.artifacts.0.content', ArtifactVersion::query()->findOrFail($first)->content)
        ->assertJsonPath('task.artifacts.0.version.source', ArtifactVersionSource::Restore->value);
    expectJournalConsistent($task);
});

it('asks the agent to rewrite a piece', function (): void {
    [$token, $task] = ripeApiTask();
    $piece = firstPiece($task);
    $before = $piece->current_version_id;
    config(['ripe.agent.pace' => 'calm']);

    api($token)->postJson("/api/artifacts/{$piece->id}/revise", ['instruction' => '  Make it warmer.  '])
        ->assertOk()
        ->assertJsonPath('task.status', 'ripening')
        ->assertJsonPath('task.activity.kind', 'revise')
        ->assertJsonPath('task.activity.subject', $piece->title)
        ->assertJsonPath('task.activity.deferredReason', null);

    expect(ripen($task)->status)->toBe(TaskStatus::Ripe);

    $version = $piece->refresh()->currentVersion;

    expect($version?->instruction)->toBe('Make it warmer.')
        ->and($version?->parent_version_id)->toBe($before)
        ->and($version?->inputs['base_version_id'] ?? null)->toBe($before);
    expectJournalConsistent($task);
});

it('reports the agent status', function (): void {
    fakeAgent();

    api()->getJson('/api/agent')
        ->assertOk()
        ->assertJsonStructure(AGENT_KEYS)
        ->assertJsonPath('hosting', 'public')
        ->assertJsonPath('driver', 'fake')
        ->assertJsonPath('online', true)
        ->assertJsonPath('models', [])
        ->assertJsonPath('settings', null);
});

it('lets the local operator change the agent settings', function (): void {
    fakeAgent();
    config(['ripe.mode' => 'local']);

    api()->putJson('/api/agent', ['pace' => 'calm', 'paused' => true])
        ->assertOk()
        ->assertJsonPath('hosting', 'local')
        ->assertJsonPath('mode', 'paused')
        ->assertJsonPath('settings.pace', 'calm')
        ->assertJsonPath('settings.paused', true);
});

it('opens the community garden example', function (): void {
    $response = api()->postJson('/api/examples')->assertOk();

    $response->assertJsonPath('task.isExample', true)
        ->assertJsonPath('task.status', 'ripe')
        ->assertJsonPath('task.title', 'Community garden proposal');

    api()->postJson('/api/examples')->assertOk()->assertJsonPath('task.id', $response->json('task.id'));
    api()->getJson('/api/garden')->assertJsonPath('tasks', []);
});
