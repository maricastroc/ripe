<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\PlantTask;
use App\Enums\Pace;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Growth\GrowthCalculator;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\TaskEvent;
use App\Models\Visitor;
use App\Orchestration\AgentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

#[Signature('ripe:simulate {prompt=Write a short thank-you note to the neighbour who watered my plants.} {--driver=fake} {--pace=instant} {--timeout=120}')]
#[Description('Plant a task and let real queue workers ripen it, printing its journal')]
final class SimulateTaskCommand extends Command
{
    private const array SETTLED = [TaskStatus::Ripe, TaskStatus::Waiting, TaskStatus::Stalled, TaskStatus::Dormant, TaskStatus::Harvested];

    public function handle(PlantTask $plant, GrowthCalculator $growth, AgentSettings $settings, InferenceManager $inference): int
    {
        Config::set('inference.driver', (string) $this->option('driver'));
        Config::set('ripe.agent.pace', (Pace::tryFrom((string) $this->option('pace')) ?? Pace::Instant)->value);
        $inference->forgetDrivers();

        $visitor = Visitor::query()->create(['token_hash' => Visitor::hashToken(Str::random(64)), 'last_seen_at' => CarbonImmutable::now()]);
        $task = $plant->handle($visitor, (string) $this->argument('prompt'));
        $deadline = microtime(true) + (int) $this->option('timeout');

        $this->info("Planted task {$task->id} with the {$inference->current()->name()} driver.");

        while (microtime(true) < $deadline) {
            $this->callSilently('queue:work', [
                'connection' => $settings->queueConnection(),
                '--queue' => $settings->queue(),
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--timeout' => $settings->workerTimeout(),
            ]);

            $task->refresh();
            $next = AgentRun::query()->where('task_id', $task->id)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->min('available_at');

            if ($next === null && in_array($task->status, self::SETTLED, true)) {
                break;
            }

            usleep(200_000);
        }

        $this->table(['#', 'event', 'details'], TaskEvent::query()->where('task_id', $task->id)->orderBy('sequence')->get()->map(
            static fn (TaskEvent $event): array => [$event->sequence, $event->type->value, Str::limit((string) json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 90)],
        )->all());

        $plant = $growth->forTask($task);
        $this->info("Status: {$task->status->value}. Stage: {$plant->stage->value}. Steps: {$plant->stepsDone}/{$plant->stepsTotal}.");

        return $task->status === TaskStatus::Ripe ? self::SUCCESS : self::FAILURE;
    }
}
