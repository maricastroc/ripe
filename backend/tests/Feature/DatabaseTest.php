<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('runs on PostgreSQL 17 or newer', function (): void {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe('pgsql')
        ->and(version_compare($connection->getServerVersion(), '17.0', '>='))->toBeTrue();
});

it('provides the queue and cache tables the backend relies on', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks']);
