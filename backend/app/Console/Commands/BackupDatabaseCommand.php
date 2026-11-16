<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('ripe:backup')]
#[Description('Dump the database to the private backup bucket and keep only the latest dumps')]
final class BackupDatabaseCommand extends Command
{
    public function handle(): int
    {
        $disk = Config::get('ripe.backup.disk');

        if (! is_string($disk) || $disk === '') {
            $this->line('Backups are not configured.');

            return self::SUCCESS;
        }

        $folder = Config::string('ripe.backup.folder');
        $key = $folder.'/ripe-'.CarbonImmutable::now('UTC')->format('Y-m-d\THis\Z').'.dump';
        $file = sys_get_temp_dir().'/ripe-backup-'.bin2hex(random_bytes(16)).'.dump';

        try {
            $this->dump($file);
            $stream = fopen($file, 'rb') ?: throw new RuntimeException('The dump could not be read.');
            Storage::disk($disk)->writeStream($key, $stream);
            $bytes = (int) filesize($file);
        } finally {
            @unlink($file);
        }

        $removed = $this->prune($disk, $folder, max(1, Config::integer('ripe.backup.keep')));

        Log::info('Database backed up.', ['key' => $key, 'bytes' => $bytes, 'removed' => $removed]);
        $this->line("saved {$key} ({$bytes} bytes), removed {$removed} older");

        return self::SUCCESS;
    }

    private function dump(string $file): void
    {
        $connection = DB::connection();
        $setting = static fn (string $name): string => is_scalar($value = $connection->getConfig($name)) ? (string) $value : '';

        $result = Process::timeout(900)
            ->env(['PGPASSWORD' => $setting('password')])
            ->run([
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--host='.$setting('host'),
                '--port='.$setting('port'),
                '--username='.$setting('username'),
                '--dbname='.$setting('database'),
                '--file='.$file,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_dump failed: '.mb_substr(trim($result->errorOutput()), 0, 300));
        }
    }

    private function prune(string $disk, string $folder, int $keep): int
    {
        $dumps = collect(Storage::disk($disk)->files($folder))
            ->filter(static fn (string $path): bool => str_ends_with($path, '.dump'))
            ->sortDesc()
            ->values();

        $old = $dumps->slice($keep)->all();

        if ($old !== []) {
            Storage::disk($disk)->delete($old);
        }

        return count($old);
    }
}
