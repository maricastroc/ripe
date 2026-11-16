<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Table(key: 'key', keyType: 'string', incrementing: false)]
#[WithoutTimestamps]
class Example extends Model
{
    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'installed_at' => 'immutable_datetime',
        ];
    }
}
