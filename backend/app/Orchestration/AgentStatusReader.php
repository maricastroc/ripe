<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\AgentMode;
use App\Enums\Inference\HealthStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Inference\Contracts\ModelDriver;
use App\Inference\DriverHealth;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

final readonly class AgentStatusReader
{
    private const int HEALTH_SECONDS = 10;

    public function __construct(
        private AgentSettings $settings,
        private InferenceManager $inference,
        private AgentHalt $halt,
        private WorkerHeartbeat $heartbeat,
    ) {}

    public function for(?Visitor $visitor): AgentStatus
    {
        $local = ! $this->settings->isPublic();
        $driver = $this->inference->current();
        $health = $this->health($driver);
        $running = AgentRun::query()->where('status', RunStatus::Running)->with(['task:id,visitor_id,title', 'step:id,title', 'artifact:id,title'])->get();
        $mine = $visitor === null ? null : $running->first(static fn (AgentRun $run): bool => $run->task?->visitor_id === $visitor->id);
        $elsewhere = $running->contains(static fn (AgentRun $run): bool => $visitor === null || $run->task?->visitor_id !== $visitor->id);
        $unavailable = $local ? $health->message : 'The model cannot be reached right now.';

        [$mode, $message] = match (true) {
            $this->settings->isPaused() => [AgentMode::Paused, 'The agent is paused in settings.'],
            $this->halt->isHalted() => [AgentMode::Halted, $local ? $this->halt->reason() : 'The greenhouse is resting for a while.'],
            $mine !== null => [AgentMode::Working, null],
            $health->status === HealthStatus::Unreachable => [AgentMode::Offline, $unavailable],
            $health->status === HealthStatus::Misconfigured => [$local ? AgentMode::ModelMissing : AgentMode::Offline, $unavailable],
            $running->isEmpty() && ! $this->heartbeat->isAlive() => [AgentMode::Stopped, 'The agent is not running.'],
            default => [AgentMode::Idle, null],
        };

        return new AgentStatus(
            hosting: $this->settings->hosting(),
            mode: $mode,
            message: $message,
            driver: $driver->name(),
            model: $driver->model(),
            online: $health->status === HealthStatus::Available,
            models: $local ? $health->models : [],
            settings: $local ? new AgentPreferences($this->settings->pace(), $this->settings->isPaused(), $this->settings->ollamaModel()) : null,
            current: $mine,
            elsewhere: $elsewhere,
            queued: $visitor === null ? 0 : AgentRun::query()
                ->where('status', RunStatus::Queued)
                ->whereHas('task', static fn (Builder $query): Builder => $query->where('visitor_id', $visitor->id)->whereIn('status', [TaskStatus::Ripening, TaskStatus::Waiting]))
                ->count(),
        );
    }

    private function health(ModelDriver $driver): DriverHealth
    {
        $cached = Cache::remember("ripe:health:{$driver->name()}:{$driver->model()}", self::HEALTH_SECONDS, static function () use ($driver): array {
            $health = $driver->health();

            return ['status' => $health->status->value, 'message' => $health->message, 'models' => $health->models];
        });

        return new DriverHealth(HealthStatus::from($cached['status']), $cached['message'], $cached['models']);
    }
}
