<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\Inference\HealthStatus;
use App\Enums\Pace;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Exceptions\AgentSettingsLocked;
use App\Exceptions\AgentSettingsRejected;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\AgentSetting;
use App\Orchestration\AgentSettings;
use App\Orchestration\RunDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class UpdateAgentSettings
{
    public function __construct(
        private AgentSettings $settings,
        private InferenceManager $inference,
        private RunDispatcher $dispatcher,
    ) {}

    public function handle(?Pace $pace = null, ?bool $paused = null, ?string $model = null): AgentSetting
    {
        if ($this->settings->isPublic()) {
            throw new AgentSettingsLocked(__('This greenhouse is configured by its host.'));
        }

        $model = $model === null ? null : trim($model);

        if ($model !== null) {
            $this->ensureInstalled($model);
        }

        return DB::transaction(function () use ($pace, $paused, $model): AgentSetting {
            $setting = AgentSetting::stored();
            $wasPaused = $setting->paused;

            $setting->fill(array_filter([
                'pace' => $pace,
                'paused' => $paused,
                'ollama_model' => $model,
            ], static fn (mixed $value): bool => $value !== null));
            $setting->save();

            if ($wasPaused && ! $setting->paused) {
                $this->wakePausedRuns();
            }

            return $setting;
        });
    }

    private function ensureInstalled(string $model): void
    {
        if ($model === '') {
            throw new AgentSettingsRejected(__('Choose a model.'));
        }

        $health = $this->inference->current('ollama')->health();

        if ($health->status === HealthStatus::Unreachable) {
            throw new AgentSettingsRejected(__("Couldn't reach Ollama to check that model."));
        }

        if (! in_array($model, $health->models, true) && ! in_array("{$model}:latest", $health->models, true)) {
            throw new AgentSettingsRejected(__(':model is not installed in Ollama.', ['model' => $model]));
        }
    }

    private function wakePausedRuns(): void
    {
        $now = CarbonImmutable::now();
        $runs = AgentRun::query()
            ->where('status', RunStatus::Queued)
            ->where('deferred_reason', RunDeferral::Paused)
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->wake($now);
            $this->dispatcher->dispatch($run, $now);
        }
    }
}
