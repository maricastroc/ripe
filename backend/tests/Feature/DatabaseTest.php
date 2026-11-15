<?php

declare(strict_types=1);

use App\Database\PrecisePostgresGrammar;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps microseconds when a query binds a date', function (): void {
    $moment = CarbonImmutable::parse('2026-11-15 12:00:00.123456', 'UTC');
    $visitor = Visitor::factory()->create();

    DB::table('visitors')->where('id', $visitor->id)->update(['last_seen_at' => $moment]);

    expect(DB::connection()->getQueryGrammar())->toBeInstanceOf(PrecisePostgresGrammar::class)
        ->and($visitor->refresh()->last_seen_at?->format('Y-m-d H:i:s.u'))->toBe('2026-11-15 12:00:00.123456')
        ->and(DB::table('visitors')->where('last_seen_at', '>', $moment->subMicrosecond())->count())->toBe(1)
        ->and(DB::table('visitors')->where('last_seen_at', '>', $moment)->count())->toBe(0);
});
