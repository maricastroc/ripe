<?php

declare(strict_types=1);

namespace App\Examples;

use App\Actions\Tasks\ForgetTask;
use App\Enums\EventActor;
use App\Models\Task;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class ExampleLibrary
{
    public function __construct(
        private ExampleRecorder $recorder,
        private ExampleArchive $archive,
        private ForgetTask $forget,
    ) {}

    public static function directory(): string
    {
        return Config::string('ripe.examples.directory');
    }

    /** @return list<array{key: string, prompt: string, answers: array<string, string>}> */
    public function catalogue(string $directory): array
    {
        $catalogue = require "{$directory}/catalogue.php";

        if (! is_array($catalogue)) {
            throw new RuntimeException('The example catalogue must return a list.');
        }

        $definitions = [];

        foreach ($catalogue as $entry) {
            if (! is_array($entry) || ! is_string($entry['key'] ?? null) || ! is_string($entry['prompt'] ?? null)) {
                throw new RuntimeException('Every example needs a key and a prompt.');
            }

            $answers = [];

            foreach (is_array($entry['answers'] ?? null) ? $entry['answers'] : [] as $topic => $answer) {
                if (! is_string($topic) || ! is_string($answer)) {
                    throw new RuntimeException("The answers of {$entry['key']} must map a topic to an answer.");
                }

                $answers[$topic] = $answer;
            }

            $definitions[] = ['key' => $entry['key'], 'prompt' => $entry['prompt'], 'answers' => $answers];
        }

        return $definitions;
    }

    /**
     * @param  array{key: string, prompt: string, answers: array<string, string>}  $definition
     * @param  Closure(CarbonImmutable): void  $waitUntil
     */
    public function record(array $definition, string $directory, Closure $waitUntil): string
    {
        $task = $this->recorder->grow($definition['prompt'], $definition['answers'], $waitUntil);
        $archive = $this->archive->export($task, $definition['key'], CarbonImmutable::now());
        $path = "{$directory}/{$definition['key']}.json";

        try {
            file_put_contents($path, json_encode($archive, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        } catch (JsonException $exception) {
            throw new RuntimeException("The example {$definition['key']} could not be written: {$exception->getMessage()}");
        }

        DB::transaction(function () use ($task): void {
            $gardener = $task->visitor_id;
            $this->forget->handle(Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail(), EventActor::System);
            Visitor::query()->whereKey($gardener)->delete();
        });

        return $path;
    }

    /** @return array{installed: int, unchanged: int} */
    public function install(string $directory): array
    {
        $installed = 0;
        $unchanged = 0;

        foreach (glob("{$directory}/*.json") ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            try {
                $archive = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("The example {$file} is not valid JSON: {$exception->getMessage()}");
            }

            if (! is_array($archive)) {
                throw new RuntimeException("The example {$file} is not an object.");
            }

            $this->archive->install($archive, hash('sha256', $contents)) ? $installed++ : $unchanged++;
        }

        return ['installed' => $installed, 'unchanged' => $unchanged];
    }
}
