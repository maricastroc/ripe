<?php

declare(strict_types=1);

use App\Actions\Artifacts\CommitDraft;
use App\Actions\Artifacts\DiscardDraft;
use App\Actions\Artifacts\RestoreVersion;
use App\Actions\Artifacts\SaveDraft;
use App\Actions\Tasks\AnswerQuestion;
use App\Actions\Tasks\RequestRevision;
use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\TaskActionRejected;
use App\Exceptions\VersionConflict;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactDraft;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/** @return array{Task, Artifact} */
function ripePiece(): array
{
    fakeAgent();
    $task = ripen(plantTask());

    return [$task, firstPiece($task)];
}

/** @return list<string> */
function versionFingerprint(): array
{
    return ArtifactVersion::query()->orderBy('id')->get()->map(static fn (ArtifactVersion $version): string => "{$version->id}:{$version->content_hash}:{$version->number}")->all();
}

function conflictOf(Closure $action): VersionConflict
{
    try {
        $action();
    } catch (VersionConflict $conflict) {
        return $conflict;
    }

    throw new RuntimeException('Expected a version conflict.');
}

it('autosaves into a draft and leaves every version untouched', function (): void {
    [$task, $piece] = ripePiece();
    $before = versionFingerprint();
    $events = $task->refresh()->version;
    $base = (string) $piece->current_version_id;

    $first = app(SaveDraft::class)->handle($piece, 'Dear neighbour, thank you.', $base);
    $second = app(SaveDraft::class)->handle($piece, 'Dear neighbour, thank you so much.', $base, $first?->revision);

    expect($second?->revision)->toBe(2)
        ->and($second?->content)->toBe('Dear neighbour, thank you so much.')
        ->and($second?->base_version_id)->toBe($base)
        ->and(versionFingerprint())->toBe($before)
        ->and($piece->refresh()->current_version_id)->toBe($base)
        ->and($task->refresh()->version)->toBe($events);
    expectJournalConsistent($task);
});

it('keeps a draft as exactly one new immutable version', function (): void {
    [$task, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    $before = versionFingerprint();
    $draft = app(SaveDraft::class)->handle($piece, 'Dear neighbour, thank you for the tomatoes.', $base);

    $version = app(CommitDraft::class)->handle($piece, $base, (int) $draft?->revision);

    expect($version->source)->toBe(ArtifactVersionSource::UserEdit)
        ->and($version->content)->toBe('Dear neighbour, thank you for the tomatoes.')
        ->and($version->parent_version_id)->toBe($base)
        ->and($piece->refresh()->current_version_id)->toBe($version->id)
        ->and(ArtifactDraft::query()->count())->toBe(0)
        ->and(array_diff(versionFingerprint(), $before))->toHaveCount(1)
        ->and(array_diff($before, versionFingerprint()))->toBe([])
        ->and(TaskEvent::query()->where('type', TaskEventType::ArtifactVersionCreated)->latest('sequence')->first()?->payload['source'])->toBe('user_edit');
    expectJournalConsistent($task);
});

it('drops the draft once the text matches the current version again', function (): void {
    [, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'Something else entirely.', $base);

    expect(app(SaveDraft::class)->handle($piece, (string) $piece->currentVersion?->content, $base, $draft?->revision))->toBeNull()
        ->and(ArtifactDraft::query()->count())->toBe(0);
});

it('refuses edits from a tab that missed the latest autosave', function (): void {
    [, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'Edited in the first tab.', $base);
    app(SaveDraft::class)->handle($piece, 'Edited again in the first tab.', $base, $draft?->revision);

    $fresh = conflictOf(fn () => app(SaveDraft::class)->handle($piece, 'Edited in a second tab.', $base));
    $stale = conflictOf(fn () => app(CommitDraft::class)->handle($piece, $base, 1));
    $discard = conflictOf(fn () => app(DiscardDraft::class)->handle($piece, 1));

    expect([$fresh->draftRevision, $stale->draftRevision, $discard->draftRevision])->toBe([2, 2, 2])
        ->and($fresh->currentVersionId)->toBe($base)
        ->and(ArtifactDraft::query()->sole()->content)->toBe('Edited again in the first tab.');
});

it('refuses a draft kept from a version that another tab already replaced', function (): void {
    [$task, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'Kept in the first tab.', $base);
    $kept = app(CommitDraft::class)->handle($piece, $base, (int) $draft?->revision);

    $conflict = conflictOf(fn () => app(SaveDraft::class)->handle($piece, 'Typed in the second tab.', $base));

    expect($conflict->currentVersionId)->toBe($kept->id)
        ->and($conflict->draftRevision)->toBeNull()
        ->and(ArtifactDraft::query()->count())->toBe(0);
    expectJournalConsistent($task);
});

it('keeps a draft when the agent rewrites the piece underneath it, and lets the visitor rebase it', function (): void {
    fakeAgent([question('Which day works for the visit?')]);
    $task = ripen(plantTask());
    $piece = firstPiece($task);
    $base = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'My own words for the outline.', $base);

    app(AnswerQuestion::class)->handle(Question::query()->sole(), 'Saturday morning.');
    $task = ripen($task);
    $current = (string) $piece->refresh()->current_version_id;

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($current)->not->toBe($base)
        ->and($piece->currentVersion?->source)->toBe(ArtifactVersionSource::Revise)
        ->and(ArtifactDraft::query()->sole()->base_version_id)->toBe($base)
        ->and(conflictOf(fn () => app(CommitDraft::class)->handle($piece, $base, (int) $draft?->revision))->currentVersionId)->toBe($current)
        ->and(conflictOf(fn () => app(SaveDraft::class)->handle($piece, 'Still typing.', $base, $draft?->revision))->currentVersionId)->toBe($current);

    $rebased = app(SaveDraft::class)->handle($piece, 'My own words, merged with the new outline.', $current, $draft?->revision);
    $kept = app(CommitDraft::class)->handle($piece, $current, (int) $rebased?->revision);

    expect($kept->parent_version_id)->toBe($current)
        ->and($kept->content)->toBe('My own words, merged with the new outline.');
    expectJournalConsistent($task);
});

it('goes back to the previous version or to a chosen one, never over pending edits', function (): void {
    [$task, $piece] = ripePiece();
    $first = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'A second version.', $first);
    $second = app(CommitDraft::class)->handle($piece, $first, (int) $draft?->revision);

    $restored = app(RestoreVersion::class)->handle($piece, $second->id);

    expect($restored->source)->toBe(ArtifactVersionSource::Restore)
        ->and($restored->content)->toBe(ArtifactVersion::query()->findOrFail($first)->content)
        ->and($restored->parent_version_id)->toBe($second->id)
        ->and($restored->inputs['restored_version_id'] ?? null)->toBe($first);

    $again = app(RestoreVersion::class)->handle($piece, $restored->id, $second->id);
    app(SaveDraft::class)->handle($piece, 'Unsaved words.', $again->id);

    expect($again->content)->toBe('A second version.')
        ->and(conflictOf(fn () => app(RestoreVersion::class)->handle($piece, $again->id, $first))->draftRevision)->toBe(1)
        ->and(fn () => app(RestoreVersion::class)->handle($piece, $restored->id))->toThrow(VersionConflict::class);
    expectJournalConsistent($task);
});

it('refuses to restore when there is nothing earlier or the version is already current', function (): void {
    [, $piece] = ripePiece();
    $current = (string) $piece->current_version_id;

    expect(fn () => app(RestoreVersion::class)->handle($piece, $current))->toThrow(TaskActionRejected::class, 'There is no earlier version to go back to.')
        ->and(fn () => app(RestoreVersion::class)->handle($piece, $current, $current))->toThrow(TaskActionRejected::class, 'That is already the current version.');
});

it('keeps the visitor pending edits before the agent rewrites a piece', function (): void {
    [$task, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    app(SaveDraft::class)->handle($piece, 'Dear neighbour, my own opening line, then the rest of the note.', $base);

    $run = app(RequestRevision::class)->handle($piece, 'Make it warmer.');
    $kept = ArtifactVersion::query()->findOrFail($run->base_version_id);

    expect($kept->source)->toBe(ArtifactVersionSource::UserEdit)
        ->and($kept->content)->toBe('Dear neighbour, my own opening line, then the rest of the note.')
        ->and($kept->parent_version_id)->toBe($base)
        ->and(ArtifactDraft::query()->count())->toBe(0);

    $task = ripen($task);

    expect($piece->refresh()->currentVersion?->parent_version_id)->toBe($kept->id)
        ->and($task->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('asks the visitor to settle an outdated draft before the agent rewrites the piece', function (): void {
    [$task, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    app(SaveDraft::class)->handle($piece, 'A draft that will fall behind.', $base);
    DB::transaction(static fn () => $piece->addVersion('A newer version written elsewhere, long enough to stand as a piece of the note.', ArtifactVersionSource::Restore, EventActor::Visitor));
    $runs = AgentRun::query()->count();

    expect(fn () => app(RequestRevision::class)->handle($piece->refresh(), 'Shorter.'))->toThrow(VersionConflict::class, 'Keep or discard your edits to this piece first.')
        ->and(AgentRun::query()->count())->toBe($runs);
    expectJournalConsistent($task);
});

it('keeps a draft only once when two processes race to keep it', function (): void {
    [$task, $piece] = ripePiece();
    $base = (string) $piece->current_version_id;
    $draft = app(SaveDraft::class)->handle($piece, 'Kept exactly once.', $base);
    $startAt = (string) (microtime(true) + 1.5);

    $results = Process::pool(function (Pool $pool) use ($piece, $base, $draft, $startAt): void {
        foreach (range(1, 2) as $process) {
            $pool->path(base_path())->env(['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'CACHE_STORE' => 'array'])
                ->command([PHP_BINARY, 'tests/Support/commit-draft.php', $piece->id, $base, (string) $draft?->revision, $startAt]);
        }
    })->start()->wait();

    $outputs = collect($results)->map(static fn ($result): string => trim($result->output()))->sort()->values()->all();

    expect($outputs)->toBe(['conflict', 'kept 2'])
        ->and(ArtifactVersion::query()->where('artifact_id', $piece->id)->count())->toBe(2)
        ->and(ArtifactDraft::query()->count())->toBe(0);
    expectJournalConsistent($task);
});
