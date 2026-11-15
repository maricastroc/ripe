<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(static function (Model $model): never {
            throw ImmutableRecord::cannotUpdate($model::class);
        });

        static::deleting(static function (Model $model): never {
            throw ImmutableRecord::cannotDelete($model::class);
        });
    }
}
