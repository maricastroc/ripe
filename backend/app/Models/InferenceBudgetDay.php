<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InferenceBudgetDayFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Table(key: 'day', keyType: 'string', incrementing: false)]
#[Fillable(['day', 'limit_microusd', 'reserved_microusd', 'spent_microusd', 'estimated_microusd'])]
class InferenceBudgetDay extends Model
{
    /** @use HasFactory<InferenceBudgetDayFactory> */
    use HasFactory;

    protected $attributes = [
        'reserved_microusd' => 0,
        'spent_microusd' => 0,
        'estimated_microusd' => 0,
    ];

    protected function casts(): array
    {
        return [
            'limit_microusd' => 'integer',
            'reserved_microusd' => 'integer',
            'spent_microusd' => 'integer',
            'estimated_microusd' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
