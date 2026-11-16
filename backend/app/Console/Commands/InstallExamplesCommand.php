<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Examples\ExampleLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ripe:examples:install')]
#[Description('Install the recorded public examples, replacing only the ones whose fixture changed')]
final class InstallExamplesCommand extends Command
{
    public function handle(ExampleLibrary $library): int
    {
        $result = $library->install(ExampleLibrary::directory());
        $this->line("installed {$result['installed']}, unchanged {$result['unchanged']}");

        return self::SUCCESS;
    }
}
