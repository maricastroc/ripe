<?php

declare(strict_types=1);

namespace App\Examples;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final class ExampleArchive
{
    /** @return array{key: string, driver: string, model: string, generated_at: string, rows: array<string, list<array<array-key, mixed>>>} */
    public function export(Task $task, string $key, CarbonImmutable $generatedAt): array
    {
        $runs = DB::table('agent_runs')->where('task_id', $task->id)->orderBy('created_at')->orderBy('id')->get();
        $attempts = DB::table('run_attempts')->whereIn('run_id', $runs->pluck('id'))->orderBy('started_at')->orderBy('id')->get();
        $artifacts = DB::table('artifacts')->where('task_id', $task->id)->orderBy('created_at')->orderBy('id')->get();
        $model = $attempts->first(static fn (stdClass $attempt): bool => $attempt->outcome === 'succeeded');

        if (! $model instanceof stdClass || ! is_string($model->driver) || ! is_string($model->model)) {
            throw new RuntimeException('The example has no successful model call to credit.');
        }

        $rows = [
            'tasks' => [array_replace((array) DB::table('tasks')->where('id', $task->id)->sole(), ['is_example' => true, 'visitor_id' => null])],
            'steps' => DB::table('steps')->where('task_id', $task->id)->orderBy('position')->get(),
            'step_dependencies' => DB::table('step_dependencies')->where('task_id', $task->id)->orderBy('step_id')->orderBy('depends_on_step_id')->get(),
            'questions' => DB::table('questions')->where('task_id', $task->id)->orderBy('position')->get(),
            'step_requirements' => DB::table('step_requirements')->where('task_id', $task->id)->orderBy('step_id')->orderBy('question_id')->get(),
            'artifacts' => $artifacts,
            'agent_runs' => $runs,
            'run_attempts' => $attempts->map(static fn (stdClass $attempt): array => array_replace((array) $attempt, ['budget_visitor_id' => null])),
            'artifact_versions' => DB::table('artifact_versions')->whereIn('artifact_id', $artifacts->pluck('id'))->orderBy('artifact_id')->orderBy('number')->get(),
            'task_events' => DB::table('task_events')->where('task_id', $task->id)->orderBy('sequence')->get()->map(static fn (stdClass $event): array => array_diff_key((array) $event, ['id' => true])),
        ];

        return [
            'key' => $key,
            'driver' => $model->driver,
            'model' => $model->model,
            'generated_at' => $generatedAt->utc()->toIso8601ZuluString(),
            'rows' => array_map(static fn (iterable $table): array => array_values(array_map(static fn (mixed $row): array => (array) $row, is_array($table) ? $table : iterator_to_array($table))), $rows),
        ];
    }

    /** @param array<array-key, mixed> $archive */
    public function install(array $archive, string $checksum): bool
    {
        $key = is_string($archive['key'] ?? null) ? $archive['key'] : throw new RuntimeException('The example has no key.');
        $rows = is_array($archive['rows'] ?? null) ? $archive['rows'] : throw new RuntimeException("The example {$key} has no rows.");
        $task = self::rows($rows, 'tasks')[0] ?? throw new RuntimeException("The example {$key} has no task.");

        return DB::transaction(static function () use ($archive, $key, $rows, $task, $checksum): bool {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ripe:examples']);
            $installed = DB::table('examples')->where('key', $key)->first();

            if ($installed instanceof stdClass && $installed->checksum === $checksum) {
                return false;
            }

            Task::query()->whereKey(array_filter([$installed->task_id ?? null, $task['id'] ?? null]))->delete();

            $versions = self::rows($rows, 'artifact_versions');
            $artifacts = self::rows($rows, 'artifacts');
            $runs = self::rows($rows, 'agent_runs');

            DB::table('tasks')->insert($task);
            DB::table('steps')->insert(self::rows($rows, 'steps'));
            DB::table('step_dependencies')->insert(self::rows($rows, 'step_dependencies'));
            DB::table('questions')->insert(self::rows($rows, 'questions'));
            DB::table('step_requirements')->insert(self::rows($rows, 'step_requirements'));
            DB::table('artifacts')->insert(array_map(static fn (array $artifact): array => array_replace($artifact, ['current_version_id' => null]), $artifacts));
            DB::table('agent_runs')->insert(array_map(static fn (array $run): array => array_replace($run, ['base_version_id' => null]), $runs));
            DB::table('run_attempts')->insert(self::rows($rows, 'run_attempts'));
            DB::table('artifact_versions')->insert($versions);

            foreach ($runs as $run) {
                if (($run['base_version_id'] ?? null) !== null) {
                    DB::table('agent_runs')->where('id', $run['id'])->update(['base_version_id' => $run['base_version_id']]);
                }
            }

            foreach ($artifacts as $artifact) {
                DB::table('artifacts')->where('id', $artifact['id'])->update(['current_version_id' => $artifact['current_version_id'] ?? null]);
            }

            DB::table('task_events')->insert(array_map(static fn (array $event): array => array_diff_key($event, ['id' => true]), self::rows($rows, 'task_events')));
            DB::table('examples')->insert([
                'key' => $key,
                'task_id' => $task['id'],
                'checksum' => $checksum,
                'driver' => $archive['driver'],
                'model' => $archive['model'],
                'generated_at' => $archive['generated_at'],
                'installed_at' => CarbonImmutable::now(),
            ]);

            return true;
        });
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $rows, string $table): array
    {
        $list = $rows[$table] ?? [];

        if (! is_array($list)) {
            throw new RuntimeException("The example rows for {$table} are not a list.");
        }

        return array_values(array_filter($list, is_array(...)));
    }
}
