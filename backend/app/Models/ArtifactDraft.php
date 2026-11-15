<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable(['artifact_id', 'base_version_id', 'content', 'revision'])]
#[Table(key: 'artifact_id', keyType: 'string', incrementing: false)]
class ArtifactDraft extends Model
{
    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return BelongsTo<ArtifactVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(ArtifactVersion::class, 'base_version_id');
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
