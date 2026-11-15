<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Pace;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable(['pace', 'paused', 'ollama_model'])]
#[Table(name: 'agent_settings', incrementing: false)]
final class AgentSetting extends Model
{
    public const int ID = 1;

    public const null CREATED_AT = null;

    public static function current(): self
    {
        return self::query()->find(self::ID) ?? new self(['paused' => false]);
    }

    public static function stored(): self
    {
        $setting = self::query()->whereKey(self::ID)->lockForUpdate()->first();

        if ($setting !== null) {
            return $setting;
        }

        $setting = new self(['paused' => false]);
        $setting->id = self::ID;

        return $setting;
    }

    protected function casts(): array
    {
        return [
            'pace' => Pace::class,
            'paused' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
