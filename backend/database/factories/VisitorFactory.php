<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Visitor> */
class VisitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'token_hash' => Visitor::hashToken(Str::random(64)),
            'last_seen_at' => CarbonImmutable::now(),
        ];
    }
}
