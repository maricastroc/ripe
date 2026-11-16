<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('dumps the database to the private bucket without the password on the command line, and keeps only the latest dumps', function (): void {
    config(['ripe.backup.disk' => 'backups', 'ripe.backup.keep' => 2]);
    Storage::fake('backups');
    Storage::disk('backups')->put('postgres/ripe-2026-11-13T033000Z.dump', 'older');
    Storage::disk('backups')->put('postgres/ripe-2026-11-14T033000Z.dump', 'old');
    Storage::disk('backups')->put('postgres/notes.txt', 'kept');
    Date::setTestNow(CarbonImmutable::parse('2026-11-15 03:30:00', 'UTC'));
    Process::fake(static function (PendingProcess $process) {
        $command = is_array($process->command) ? $process->command : [];
        $file = substr((string) collect($command)->first(static fn (string $part): bool => str_starts_with($part, '--file=')), 7);
        file_put_contents($file, 'PGDMP');

        return Process::result();
    });

    $this->artisan('ripe:backup')->assertSuccessful();

    Process::assertRan(static fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === 'pg_dump'
        && in_array('--format=custom', $process->command, true)
        && collect($process->command)->every(static fn (string $part): bool => ! str_starts_with($part, '--password') && ! str_contains($part, '@'))
        && ($process->environment['PGPASSWORD'] ?? null) === config('database.connections.pgsql.password'));

    expect(Storage::disk('backups')->allFiles())->toBe([
        'postgres/notes.txt',
        'postgres/ripe-2026-11-14T033000Z.dump',
        'postgres/ripe-2026-11-15T033000Z.dump',
    ])->and(Storage::disk('backups')->get('postgres/ripe-2026-11-15T033000Z.dump'))->toBe('PGDMP');
});

it('does nothing when no backup bucket is configured', function (): void {
    Process::fake();

    $this->artisan('ripe:backup')->expectsOutputToContain('Backups are not configured.')->assertSuccessful();

    Process::assertNothingRan();
});
