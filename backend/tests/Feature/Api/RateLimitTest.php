<?php

declare(strict_types=1);

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    fakeAgent();
    Date::setTestNow(CarbonImmutable::now());
});

it('limits how many seeds one visitor plants in an hour', function (): void {
    config(['ripe.limits.plant_per_hour_per_visitor' => 2]);
    [, $token] = visitor();
    [, $neighbour] = visitor();

    api($token)->postJson('/api/tasks', ['prompt' => 'First seed.'])->assertCreated();
    api($token)->postJson('/api/tasks', ['prompt' => 'Second seed.'])->assertCreated();
    api($token)->postJson('/api/tasks', ['prompt' => 'Third seed.'])->assertTooManyRequests()->assertHeader('Retry-After');
    api($neighbour)->postJson('/api/tasks', ['prompt' => 'A neighbour seed.'])->assertCreated();

    Date::setTestNow(CarbonImmutable::now()->addHour()->addSecond());

    api($token)->postJson('/api/tasks', ['prompt' => 'A seed an hour later.'])->assertCreated();
    expect(Task::query()->count())->toBe(4);
});

it('limits seeds from one network however many visitors it creates', function (): void {
    config(['ripe.limits.plant_per_hour_per_ip' => 3]);

    foreach (range(1, 3) as $seed) {
        api()->postJson('/api/tasks', ['prompt' => "Seed number {$seed}."])->assertCreated();
    }

    api()->postJson('/api/tasks', ['prompt' => 'One seed too many.'])->assertTooManyRequests();
    api(headers: ['Sec-Fetch-Site' => 'same-origin'])->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/tasks', ['prompt' => 'Another network.'])->assertCreated();
});

it('limits changes per minute for each visitor', function (): void {
    config(['ripe.limits.mutations_per_minute' => 3]);
    [, $token] = visitor();
    $task = apiTask($token);

    foreach (range(1, 3) as $change) {
        api($token)->patchJson("/api/tasks/{$task->id}", ['title' => "Title {$change}"])->assertOk();
    }

    api($token)->patchJson("/api/tasks/{$task->id}", ['title' => 'One change too many'])->assertTooManyRequests();

    Date::setTestNow(CarbonImmutable::now()->addMinute()->addSecond());

    api($token)->patchJson("/api/tasks/{$task->id}", ['title' => 'A minute later'])->assertOk();
});
