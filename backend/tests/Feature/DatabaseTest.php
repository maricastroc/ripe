<?php

declare(strict_types=1);

use App\Database\PrecisePostgresGrammar;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs on PostgreSQL 17 or newer', function (): void {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe('pgsql')
        ->and(version_compare($connection->getServerVersion(), '17.0', '>='))->toBeTrue();
});

it('provides the tables the backend relies on', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
    'visitors', 'tasks', 'steps', 'questions', 'step_dependencies', 'step_requirements',
    'artifacts', 'artifact_versions', 'agent_runs', 'run_attempts', 'task_events', 'inference_budget_days',
]);

it('no longer carries the skeleton user tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['users', 'password_reset_tokens', 'sessions']);

it('keeps microseconds when a query binds a date', function (): void {
    $moment = CarbonImmutable::parse('2026-11-15 12:00:00.123456', 'UTC');
    $visitor = Visitor::factory()->create();

    DB::table('visitors')->where('id', $visitor->id)->update(['last_seen_at' => $moment]);

    expect(DB::connection()->getQueryGrammar())->toBeInstanceOf(PrecisePostgresGrammar::class)
        ->and($visitor->refresh()->last_seen_at?->format('Y-m-d H:i:s.u'))->toBe('2026-11-15 12:00:00.123456')
        ->and(DB::table('visitors')->where('last_seen_at', '>', $moment->subMicrosecond())->count())->toBe(1)
        ->and(DB::table('visitors')->where('last_seen_at', '>', $moment)->count())->toBe(0);
});
