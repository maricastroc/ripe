<?php

declare(strict_types=1);

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
