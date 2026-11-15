<?php

declare(strict_types=1);

use App\Actions\Agent\UpdateAgentSettings;
use App\Enums\Pace;
use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Exceptions\AgentSettingsLocked;
use App\Exceptions\AgentSettingsRejected;
use App\Inference\InferenceManager;
use App\Models\AgentRun;
use App\Models\AgentSetting;
use App\Models\RunAttempt;
use App\Orchestration\AgentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function installedOllamaModels(string ...$models): void
{
    Http::fake(['*/api/tags' => Http::response(['models' => array_map(static fn (string $name): array => ['name' => $name], $models)])]);
}

beforeEach(function (): void {
    config(['ripe.mode' => 'local']);
    Date::setTestNow(CarbonImmutable::now());
});

it('lets the local operator pause the agent and wakes the waiting runs on resume', function (): void {
    fakeAgent();
    app(UpdateAgentSettings::class)->handle(paused: true);
    $task = plantTask();
    drainAgentQueue();
    $run = interpretRun($task);

    expect($run->status)->toBe(RunStatus::Queued)
        ->and($run->deferred_reason)->toBe(RunDeferral::Paused)
        ->and($run->available_at?->equalTo(CarbonImmutable::now()->addSeconds(30)))->toBeTrue()
        ->and(RunAttempt::query()->count())->toBe(0);

    DB::table('jobs')->delete();
    app(UpdateAgentSettings::class)->handle(paused: false);

    expect($run->refresh()->available_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($run->deferred_reason)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(ripen($task)->status)->toBe(TaskStatus::Ripe);
    expectJournalConsistent($task);
});

it('paces automatic runs with the operator choice in local mode only', function (): void {
    fakeAgent();
    app(UpdateAgentSettings::class)->handle(pace: Pace::Calm);
    $task = plantTask();
    drainAgentQueue(1);
    $plan = AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Plan)->sole();

    expect($plan->available_at?->equalTo(CarbonImmutable::now()->addSeconds(30)))->toBeTrue();

    config(['ripe.mode' => 'public']);
    $other = plantTask();
    drainAgentQueue(1);
    $otherPlan = AgentRun::query()->where('task_id', $other->id)->where('kind', RunKind::Plan)->sole();

    expect($otherPlan->available_at?->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('switches a long-running worker to the chosen Ollama model without a restart', function (): void {
    installedOllamaModels('gemma4:e4b', 'qwen3:8b');
    $manager = app(InferenceManager::class);

    expect($manager->current('ollama')->model())->toBe('gemma4:e4b');

    app(UpdateAgentSettings::class)->handle(model: ' qwen3:8b ');

    expect($manager->current('ollama')->model())->toBe('qwen3:8b')
        ->and(AgentSetting::current()->ollama_model)->toBe('qwen3:8b');
});

it('accepts a model installed under its latest tag', function (): void {
    installedOllamaModels('llama3.2:latest');

    expect(app(UpdateAgentSettings::class)->handle(model: 'llama3.2')->ollama_model)->toBe('llama3.2');
});

it('refuses a model Ollama does not have, or cannot be asked about', function (): void {
    installedOllamaModels('gemma4:e4b');

    expect(fn () => app(UpdateAgentSettings::class)->handle(model: 'qwen3:8b'))->toThrow(AgentSettingsRejected::class, 'qwen3:8b is not installed in Ollama.')
        ->and(fn () => app(UpdateAgentSettings::class)->handle(model: '  '))->toThrow(AgentSettingsRejected::class, 'Choose a model.');

    Http::fake(['*/api/tags' => static fn () => throw new ConnectionException('Connection refused')]);

    expect(fn () => app(UpdateAgentSettings::class)->handle(model: 'gemma4:e4b'))->toThrow(AgentSettingsRejected::class, "Couldn't reach Ollama to check that model.")
        ->and(AgentSetting::query()->count())->toBe(0);
});

it('keeps the settings in the host hands in public mode', function (): void {
    config(['ripe.mode' => 'public']);

    expect(fn () => app(UpdateAgentSettings::class)->handle(paused: true))->toThrow(AgentSettingsLocked::class)
        ->and(AgentSetting::query()->count())->toBe(0);

    AgentSetting::stored()->fill(['paused' => true, 'pace' => Pace::Calm])->save();

    expect(app(AgentSettings::class)->isPaused())->toBeFalse()
        ->and(app(AgentSettings::class)->pace())->toBe(Pace::Instant);
});
