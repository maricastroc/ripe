<?php

declare(strict_types=1);

use App\Orchestration\AgentHalt;
use App\Orchestration\Execution\RunExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;

beforeEach(fn () => fakeAgent());

it('tells a visitor what the agent does for them and only that it works elsewhere for others', function (): void {
    [, $owner] = visitor();
    [, $neighbour] = visitor();
    $task = apiTask($owner, 'Write the note to the neighbour about the tomatoes.');
    app(RunExecutor::class)->claim(interpretRun($task)->id);

    api($owner)->getJson('/api/agent')
        ->assertOk()
        ->assertJsonPath('mode', 'working')
        ->assertJsonPath('current.taskId', $task->id)
        ->assertJsonPath('current.kind', 'interpret')
        ->assertJsonPath('elsewhere', false);

    $other = api($neighbour)->getJson('/api/agent')->assertOk();

    $other->assertJsonPath('current', null)->assertJsonPath('elsewhere', true);
    expect($other->getContent())->not->toContain($task->id)->not->toContain('tomatoes');
});

it('counts only the visitor own queued work', function (): void {
    [, $owner] = visitor();
    [, $neighbour] = visitor();
    apiTask($owner);

    api($owner)->getJson('/api/agent')->assertJsonPath('queued', 1);
    api($neighbour)->getJson('/api/agent')->assertJsonPath('queued', 0);
});

it('keeps settings, installed models and operator messages to local mode', function (): void {
    app(AgentHalt::class)->halt('Incorrect API key provided: sk-proj-abc.', CarbonImmutable::now());

    api()->getJson('/api/agent')
        ->assertJsonPath('hosting', 'public')
        ->assertJsonPath('mode', 'halted')
        ->assertJsonPath('message', 'The greenhouse is resting for a while.')
        ->assertJsonPath('settings', null)
        ->assertJsonPath('models', []);

    config(['ripe.mode' => 'local']);

    api()->getJson('/api/agent')
        ->assertJsonPath('hosting', 'local')
        ->assertJsonPath('message', 'Incorrect API key provided: sk-proj-abc.')
        ->assertJsonPath('settings.paused', false)
        ->assertJsonPath('models', ['fake-model']);
});

it('says when no worker is listening', function (): void {
    api()->getJson('/api/agent')->assertJsonPath('mode', 'stopped');

    event(new Looping('database', 'agent'));

    api()->getJson('/api/agent')->assertJsonPath('mode', 'stopped');

    event(new Looping('agent', 'agent'));

    api()->getJson('/api/agent')->assertJsonPath('mode', 'idle')->assertJsonPath('message', null);
});

it('keeps the settings locked in public mode', function (): void {
    api()->putJson('/api/agent', ['paused' => true])
        ->assertForbidden()
        ->assertJsonPath('message', 'This greenhouse is configured by its host.');
});
