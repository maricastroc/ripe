<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\AgentMode;
use App\Enums\Inference\HealthStatus;
use App\Enums\QuotaWait;
use App\Enums\RestReason;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Contracts\QuotaLimited;
use App\Inference\DriverHealth;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\Visitor;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\Quota\QuotaGate;
use App\Orchestration\Quota\QuotaStanding;
use Carbon\CarbonImmutable;
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
        private InferenceBudget $budget,
        private QuotaGate $quota,
    ) {}

    public function for(?Visitor $visitor): AgentStatus
    {
        $local = ! $this->settings->isPublic();
        $driver = $this->inference->current();
        $health = $this->health($driver);
        $running = AgentRun::query()->where('status', RunStatus::Running)->with(['task:id,visitor_id,title', 'step:id,title', 'artifact:id,title'])->get();
        $mine = $visitor === null ? null : $running->first(static fn (AgentRun $run): bool => $run->task?->visitor_id === $visitor->id);
        $elsewhere = $running->contains(static fn (AgentRun $run): bool => $visitor === null || $run->task?->visitor_id !== $visitor->id);
        $now = CarbonImmutable::now();
        $standing = $this->budget->standing($visitor, $driver->isMetered(), $now);
        $quota = $this->quotaStanding($driver, $now);

        $mode = match (true) {
            $this->settings->isPaused() => AgentMode::Paused,
            $this->halt->isHalted() => AgentMode::Halted,
            $mine !== null => AgentMode::Working,
            $quota?->wait === QuotaWait::Daily, $quota?->wait === QuotaWait::Capacity, $standing->resting => AgentMode::Resting,
            $health->status === HealthStatus::Unreachable => AgentMode::Offline,
            $health->status === HealthStatus::Misconfigured => $local ? AgentMode::ModelMissing : AgentMode::Offline,
            $running->isEmpty() && ! $this->heartbeat->isAlive() => AgentMode::Stopped,
            default => AgentMode::Idle,
        };

        return new AgentStatus(
            hosting: $this->settings->hosting(),
            mode: $mode,
            message: $this->messageOf($mode, $local, $quota, $health),
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
            resumesAt: $mode !== AgentMode::Resting ? null : ($quota?->resting() === true ? $quota->resumesAt : $standing->resumesAt),
            rest: $mode !== AgentMode::Resting ? null : match ($quota?->wait) {
                QuotaWait::Daily => RestReason::Daily,
                QuotaWait::Capacity => RestReason::Capacity,
                null => RestReason::Budget,
            },
        );
    }

    private function quotaStanding(ModelDriver $driver, CarbonImmutable $now): ?QuotaStanding
    {
        if (! $driver instanceof QuotaLimited) {
            return null;
        }

        try {
            $standing = $this->quota->standing($driver->quotaScope(), $driver->quotaLimits(), $now);
        } catch (DriverMisconfigured) {
            return null;
        }

        return $standing->resting() ? $standing : null;
    }

    private function health(ModelDriver $driver): DriverHealth
    {
        $cached = Cache::remember("ripe:health:{$driver->name()}:{$driver->model()}", self::HEALTH_SECONDS, static function () use ($driver): array {
            $health = $driver->health();

            return ['status' => $health->status->value, 'message' => $health->message, 'models' => $health->models];
        });

        return new DriverHealth(HealthStatus::from($cached['status']), $cached['message'], $cached['models']);
    }

    private function messageOf(AgentMode $mode, bool $local, ?QuotaStanding $quota, DriverHealth $health): ?string
    {
        if ($mode === AgentMode::Paused) {
            return __('The agent is paused in settings.');
        }

        if ($mode === AgentMode::Halted) {
            if ($local) {
                return $this->halt->reason();
            }

            return __('The greenhouse cannot reach its model right now.');
        }

        if ($mode === AgentMode::Resting) {
            return match ($quota?->wait) {
                QuotaWait::Daily => __('The free model has used up its allowance for now. The greenhouse rests until it has room again.'),
                QuotaWait::Capacity => __('The free model asked for a short pause.'),
                default => __('Today\'s budget is used up. The greenhouse rests until the next one opens.'),
            };
        }

        if ($mode === AgentMode::Offline || $mode === AgentMode::ModelMissing) {
            if ($local) {
                return $health->message;
            }

            return __('The model cannot be reached right now.');
        }

        if ($mode === AgentMode::Stopped) {
            return __('The agent is not running.');
        }

        return null;
    }
}
