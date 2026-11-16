<?php

declare(strict_types=1);

use App\Actions\Visitors\PruneGardens;
use App\Exceptions\Inference\ModelCallFailed;
use App\Models\InferenceBudgetDay;
use App\Models\Task;
use App\Models\Visitor;
use App\Orchestration\Execution\RunExecutor;
use App\Realtime\Signal;
use App\Retention\Retention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

it('keeps the daily limits per network and globally even when a visitor clears the cookie', function (): void {
    fakeAgent();
    config([
        'ripe.gateway.required' => true,
        'ripe.gateway.secret' => 'a-shared-gateway-secret',
        'ripe.limits.plant_per_hour_per_visitor' => 100,
        'ripe.limits.plant_per_hour_per_ip' => 100,
        'ripe.limits.plant_per_day_per_visitor' => 3,
        'ripe.limits.plant_per_day_per_network' => 4,
        'ripe.limits.plant_per_day_global' => 5,
        'ripe.limits.plants_in_bed' => 2,
    ]);
    $plant = static fn (string $address, string $token) => api($token, [
        'Sec-Fetch-Site' => 'same-origin',
        'X-Ripe-Gateway' => 'a-shared-gateway-secret',
        'X-Ripe-Client-Ip' => $address,
    ])->postJson('/api/tasks', ['prompt' => 'A seed to count against the limits.']);
    [, $first] = visitor();
    [, $clearedCookie] = visitor();
    [, $elsewhere] = visitor();
    [, $later] = visitor();

    $statuses = [
        $plant('203.0.113.7', $first)->status(),
        $plant('203.0.113.7', $first)->status(),
        $plant('203.0.113.7', $first)->assertConflict()->status(),
        $plant('203.0.113.7', $clearedCookie)->status(),
        $plant('203.0.113.7', $clearedCookie)->assertJsonPath('message', 'The greenhouse has done enough for today. Come back tomorrow.')->status(),
        $plant('198.51.100.20', $elsewhere)->status(),
        $plant('192.0.2.44', $later)->status(),
    ];

    expect($statuses)->toBe([201, 201, 409, 201, 429, 201, 429])
        ->and(Task::query()->count())->toBe(4);
});

it('forgets each task on the date the API announces, never later than ninety days, and keeps examples', function (): void {
    fakeAgent();
    $now = CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC');
    Date::setTestNow($now);
    $example = installedExample();
    $grown = static function (int $plantedDaysAgo, int $seenDaysAgo, ?int $returnInDays = null) use ($now): Task {
        $task = plantTask();
        Task::query()->whereKey($task->id)->update([
            'created_at' => $now->subDays($plantedDaysAgo),
            'return_at' => $returnInDays === null ? null : $now->addDays($returnInDays),
        ]);
        Visitor::query()->whereKey($task->visitor_id)->update(['last_seen_at' => $now->subDays($seenDaysAgo)]);

        return $task;
    };
    $abandoned = $grown(40, 31);
    $visited = $grown(20, 10);
    $ancient = $grown(91, 0);
    $awaited = $grown(40, 40, 5);
    $idle = Visitor::factory()->create(['last_seen_at' => $now->subDays(31)]);
    $recent = Visitor::factory()->create(['last_seen_at' => $now->subDays(5)]);

    $announced = CarbonImmutable::parse((string) api(visitorTokenFor($visited))->getJson("/api/tasks/{$visited->id}")->json('task.expiresAt'));
    $pruned = app(PruneGardens::class)->handle($now);

    expect($pruned)->toBe(['tasks' => 2, 'visitors' => 2])
        ->and(Task::query()->pluck('id')->sort()->values()->all())->toBe(collect([$example->id, $visited->id, $awaited->id])->sort()->values()->all())
        ->and(Visitor::query()->whereKey([$idle->id, $abandoned->visitor_id])->exists())->toBeFalse()
        ->and(Visitor::query()->whereKey([$recent->id, $ancient->visitor_id])->count())->toBe(2)
        ->and($announced->equalTo($now->addDays(30)))->toBeTrue()
        ->and(Retention::expiresAt($awaited->refresh()->load('visitor'))?->equalTo($now->addDays(35)))->toBeTrue();

    expect(app(PruneGardens::class)->handle($announced->subSecond())['tasks'])->toBe(0)
        ->and(app(PruneGardens::class)->handle($announced)['tasks'])->toBe(1)
        ->and(Task::query()->whereKey($visited->id)->exists())->toBeFalse();
});

it('deletes a whole greenhouse during a run, fencing the run and touching no other visitor', function (): void {
    config(['ripe.inference.allow_paid' => true]);
    fakeAgent()->priced(2000, 700);
    $broadcasts = recordBroadcasts();
    [$owner, $token] = visitor();
    $running = apiTask($token);
    $resting = apiTask($token);
    [, $other] = visitor();
    $kept = apiTask($other);
    $claim = app(RunExecutor::class)->claim(interpretRun($running)->id) ?? throw new RuntimeException('Not claimed.');
    $broadcasts->sent = [];

    $response = api($token)->deleteJson('/api/greenhouse')->assertNoContent();
    $cookie = collect($response->headers->getCookies())->first(static fn ($cookie): bool => $cookie->getName() === config('ripe.visitor.cookie'));
    app(RunExecutor::class)->recordFailure($claim, new ModelCallFailed('A late answer from the dead greenhouse.'));
    $day = InferenceBudgetDay::query()->sole();

    expect(Task::query()->whereKey([$running->id, $resting->id])->exists())->toBeFalse()
        ->and(Visitor::query()->whereKey($owner->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and($cookie?->isCleared())->toBeTrue()
        ->and(collect($broadcasts->of(Signal::TASK_CHANGED))->filter(static fn (array $sent): bool => $sent['payload']['deleted'] === true)->pluck('payload.taskId')->sort()->values()->all())
        ->toBe(collect([$running->id, $resting->id])->sort()->values()->all())
        ->and($day->reserved_microusd)->toBe(0)
        ->and($day->estimated_microusd)->toBe(2000);

    api($token)->getJson('/api/garden')->assertOk()->assertJsonPath('tasks', [])->assertJsonPath('channel', null);
    expectJournalConsistent($kept);
});

function visitorTokenFor(Task $task): string
{
    $token = bin2hex(random_bytes(32));
    Visitor::query()->whereKey($task->visitor_id)->update(['token_hash' => Visitor::hashToken($token)]);

    return $token;
}
