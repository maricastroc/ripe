<?php

declare(strict_types=1);

use App\Actions\Tasks\DeferTask;
use App\Actions\Tasks\RequestRevision;
use App\Enums\ArtifactKind;
use App\Enums\TaskStatus;
use App\Examples\ExampleLibrary;
use App\Exceptions\TaskActionRejected;
use App\Models\Artifact;
use App\Models\Example;
use App\Models\Task;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommunityGarden;

it('records an example through the real agent, keeps its whole history and installs it only once, even in a fresh database', function (): void {
    $freshDatabase = static fn (): bool => DB::statement("select setval(pg_get_serial_sequence('task_events', 'id'), 1, false)");
    $freshDatabase();
    $directory = recordedExamples();
    $fixture = (string) file_get_contents("{$directory}/community-garden.json");
    $archive = json_decode($fixture, true);

    expect(Task::query()->count())->toBe(0)
        ->and(Visitor::query()->count())->toBe(0)
        ->and($archive['model'])->toBe('fake-model')
        ->and(array_keys($archive['rows']))->toContain('task_events', 'artifact_versions', 'run_attempts');

    expect($archive['rows']['task_events'][0])->not->toHaveKey('id');

    $freshDatabase();

    expect(app(ExampleLibrary::class)->install($directory))->toBe(['installed' => 1, 'unchanged' => 0])
        ->and(app(ExampleLibrary::class)->install($directory))->toBe(['installed' => 0, 'unchanged' => 1])
        ->and(plantTask()->version)->toBeGreaterThan(0);

    $example = Task::query()->where('is_example', true)->sole();
    $pieces = Artifact::query()->where('task_id', $example->id)->where('kind', ArtifactKind::Step)->with('currentVersion')->get();

    expect($example->status)->toBe(TaskStatus::Ripe)
        ->and($example->visitor_id)->toBeNull()
        ->and($example->prompt)->toBe(CommunityGarden::PROMPT)
        ->and($example->version)->toBe(count($archive['rows']['task_events']))
        ->and($pieces->pluck('title')->all())->toBe(['Proposal outline', 'Draft: problem and goals', 'Budget and timeline', 'Full proposal'])
        ->and($pieces->last()?->currentVersion?->content)->toContain('[[TBD: name and contact of the person signing]]')
        ->and(fn () => app(DeferTask::class)->handle($example))->toThrow(TaskActionRejected::class)
        ->and(fn () => app(RequestRevision::class)->handle($pieces->first() ?? throw new RuntimeException, 'Shorter.'))->toThrow(TaskActionRejected::class);
    expectJournalConsistent($example);

    file_put_contents("{$directory}/community-garden.json", str_replace($archive['generated_at'], '2026-11-01T00:00:00Z', $fixture));

    expect(app(ExampleLibrary::class)->install($directory))->toBe(['installed' => 1, 'unchanged' => 0])
        ->and(Task::query()->where('is_example', true)->sole()->id)->toBe($example->id)
        ->and(Example::query()->sole()->generated_at?->toIso8601ZuluString())->toBe('2026-11-01T00:00:00Z');
    expectJournalConsistent($example->refresh());

    array_map(unlink(...), glob("{$directory}/*") ?: []);
    rmdir($directory);
});
