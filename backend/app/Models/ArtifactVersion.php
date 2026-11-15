<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\ArtifactVersionSource;
use App\Models\Concerns\Immutable;
use Database\Factories\ArtifactVersionFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $inputs */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable([
    'artifact_id', 'number', 'content', 'content_hash', 'source', 'run_id',
    'parent_version_id', 'instruction', 'inputs', 'model',
])]
class ArtifactVersion extends Model
{
    /** @use HasFactory<ArtifactVersionFactory> */
    use HasFactory;

    use HasUuids;
    use Immutable;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return BelongsTo<ArtifactVersion, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ArtifactVersion::class, 'parent_version_id');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'source' => ArtifactVersionSource::class,
            'inputs' => JsonObject::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
