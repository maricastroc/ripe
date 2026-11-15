<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArtifactKind;
use App\Enums\ArtifactVersionSource;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\Concerns\BelongsToTask;
use App\Support\Transactions;
use Database\Factories\ArtifactFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable(['task_id', 'step_id', 'kind', 'title'])]
class Artifact extends Model
{
    use BelongsToTask;

    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<Step, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    /** @return HasMany<ArtifactVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ArtifactVersion::class)->orderBy('number');
    }

    /** @return BelongsTo<ArtifactVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ArtifactVersion::class, 'current_version_id');
    }

    /** @param array<string, mixed> $inputs */
    public function addVersion(
        string $content,
        ArtifactVersionSource $source,
        EventActor $actor,
        ?AgentRun $run = null,
        ?string $instruction = null,
        array $inputs = [],
        ?string $model = null,
    ): ArtifactVersion {
        Transactions::require('Adding an artifact version');

        if ($source->isProducedByAgent() !== ($run !== null)) {
            throw new InvalidArgumentException('Agent versions need their run, and only agent versions have one.');
        }

        $task = $this->lockOwningTask();
        $parent = self::query()->whereKey($this->id)->lockForUpdate()->value('current_version_id');
        $number = ArtifactVersion::query()->where('artifact_id', $this->id)->max('number');

        $version = ArtifactVersion::query()->create([
            'artifact_id' => $this->id,
            'number' => (is_int($number) ? $number : 0) + 1,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source' => $source,
            'run_id' => $run?->id,
            'parent_version_id' => $parent,
            'instruction' => $instruction,
            'inputs' => $inputs,
            'model' => $model,
        ]);

        $this->current_version_id = $version->id;
        $this->save();

        TaskEvent::record($task, TaskEventType::ArtifactVersionCreated, [
            'artifact_id' => $this->id,
            'version_id' => $version->id,
            'number' => $version->number,
            'source' => $source->value,
        ], $actor, $run);

        return $version;
    }

    public function restore(ArtifactVersion $version, EventActor $actor = EventActor::Visitor): ArtifactVersion
    {
        if ($version->artifact_id !== $this->id) {
            throw new DomainException('A version can only be restored into its own artifact.');
        }

        return $this->addVersion($version->content, ArtifactVersionSource::Restore, $actor, inputs: [
            'restored_version_id' => $version->id,
        ]);
    }

    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
