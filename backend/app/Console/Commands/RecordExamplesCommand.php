<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Examples\ExampleLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ripe:examples:record {keys?* : Only record these examples}')]
#[Description('Grow the public examples through the real agent and save them as fixtures')]
final class RecordExamplesCommand extends Command
{
    public function handle(ExampleLibrary $library): int
    {
        $directory = ExampleLibrary::directory();
        $only = array_filter((array) $this->argument('keys'), is_string(...));

        foreach ($library->catalogue($directory) as $definition) {
            if ($only !== [] && ! in_array($definition['key'], $only, true)) {
                continue;
            }

            $this->line("recording {$definition['key']}");
            $path = $library->record($definition, $directory, function (CarbonImmutable $until): void {
                $this->line("  waiting for the model until {$until->toIso8601String()}");
                $seconds = CarbonImmutable::now()->diffInMilliseconds($until) / 1000;
                usleep((int) max(0, $seconds * 1_000_000));
            });
            $this->line("  saved {$path}");
        }

        $result = $library->install($directory);
        $this->line("installed {$result['installed']}, unchanged {$result['unchanged']}");

        return self::SUCCESS;
    }
}
