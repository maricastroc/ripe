<?php

declare(strict_types=1);

use App\Models\Question;

it('shows a draft the agent overtook as conflicting, and lets the visitor rebase and keep it', function (): void {
    fakeAgent([question('Which day works for the visit?')]);
    [, $token] = visitor();
    $task = ripen(apiTask($token));
    $piece = firstPiece($task);
    $base = (string) $piece->current_version_id;

    api($token)->putJson("/api/artifacts/{$piece->id}/draft", ['content' => 'My own outline.', 'baseVersionId' => $base, 'draftRevision' => null])->assertOk();
    api($token)->postJson('/api/questions/'.Question::query()->sole()->id.'/answer', ['answer' => 'Saturday.'])->assertOk();
    ripen($task);
    $current = (string) $piece->refresh()->current_version_id;

    api($token)->getJson("/api/tasks/{$task->id}")
        ->assertJsonPath('task.artifacts.0.draft.conflicting', true)
        ->assertJsonPath('task.artifacts.0.draft.baseVersionId', $base)
        ->assertJsonPath('task.artifacts.0.version.id', $current);

    api($token)->postJson("/api/artifacts/{$piece->id}/draft/keep", ['baseVersionId' => $base, 'draftRevision' => 1])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'This piece changed since you started editing it.',
            'currentVersionId' => $current,
            'draftRevision' => 1,
        ]);

    api($token)->putJson("/api/artifacts/{$piece->id}/draft", ['content' => 'My own outline, rebased.', 'baseVersionId' => $current, 'draftRevision' => 1])
        ->assertOk()
        ->assertJsonPath('draft.revision', 2)
        ->assertJsonPath('draft.conflicting', false);

    api($token)->postJson("/api/artifacts/{$piece->id}/draft/keep", ['baseVersionId' => $current, 'draftRevision' => 2])
        ->assertOk()
        ->assertJsonPath('task.artifacts.0.content', 'My own outline, rebased.')
        ->assertJsonPath('task.artifacts.0.draft', null);
    expectJournalConsistent($task);
});
