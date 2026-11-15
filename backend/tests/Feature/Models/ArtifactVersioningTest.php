<?php

declare(strict_types=1);

use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Exceptions\ImmutableRecord;
use App\Models\AgentRun;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('keeps every version, chained to the one before', function (): void {
    $artifact = Artifact::factory()->create();
    $run = AgentRun::factory()->forStep($artifact->step()->firstOrFail())->succeeded()->create();

    [$first, $second, $third] = DB::transaction(fn (): array => [
        $artifact->addVersion('Draft one.', ArtifactVersionSource::Generate, EventActor::Agent, $run, model: 'gemma4:e4b'),
        $artifact->addVersion('Draft two.', ArtifactVersionSource::UserEdit, EventActor::Visitor),
        $artifact->addVersion('Draft three.', ArtifactVersionSource::UserEdit, EventActor::Visitor),
    ]);

    expect([$first->number, $second->number, $third->number])->toBe([1, 2, 3])
        ->and([$first->parent_version_id, $second->parent_version_id, $third->parent_version_id])->toBe([null, $first->id, $second->id])
        ->and($artifact->fresh()?->current_version_id)->toBe($third->id)
        ->and($first->content_hash)->toBe(hash('sha256', 'Draft one.'))
        ->and($artifact->owningTask()->events()->where('type', TaskEventType::ArtifactVersionCreated)->count())->toBe(3);
});

it('restores only its own versions', function (): void {
    $artifact = Artifact::factory()->create();
    $foreign = DB::transaction(fn (): ArtifactVersion => Artifact::factory()->create()->addVersion('Elsewhere.', ArtifactVersionSource::UserEdit, EventActor::Visitor));

    expect(fn () => DB::transaction(fn () => $artifact->restore($foreign)))->toThrow(DomainException::class);
});

it('ties agent versions to their run and person versions to none', function (): void {
    $artifact = Artifact::factory()->create();
    $run = AgentRun::factory()->forStep($artifact->step()->firstOrFail())->succeeded()->create();

    expect(fn () => DB::transaction(fn () => $artifact->addVersion('x', ArtifactVersionSource::Generate, EventActor::Agent)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => DB::transaction(fn () => $artifact->addVersion('x', ArtifactVersionSource::UserEdit, EventActor::Visitor, $run)))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a single version per run, so a repeated result cannot duplicate it', function (): void {
    $artifact = Artifact::factory()->create();
    $run = AgentRun::factory()->forStep($artifact->step()->firstOrFail())->succeeded()->create();

    DB::transaction(fn () => $artifact->addVersion('Result.', ArtifactVersionSource::Generate, EventActor::Agent, $run));

    expect(fn () => DB::transaction(fn () => $artifact->addVersion('Result.', ArtifactVersionSource::Generate, EventActor::Agent, $run)))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and($artifact->versions()->count())->toBe(1)
        ->and($artifact->owningTask()->events()->count())->toBe(1);
});

it('never changes or removes a version', function (): void {
    $artifact = Artifact::factory()->create();
    $version = DB::transaction(fn (): ArtifactVersion => $artifact->addVersion('Kept.', ArtifactVersionSource::UserEdit, EventActor::Visitor));

    expect(fn () => $version->update(['content' => 'Changed.']))->toThrow(ImmutableRecord::class)
        ->and(fn () => $version->delete())->toThrow(ImmutableRecord::class)
        ->and($version->fresh()?->content)->toBe('Kept.');
});
