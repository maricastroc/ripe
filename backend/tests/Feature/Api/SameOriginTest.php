<?php

declare(strict_types=1);

use App\Models\Task;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    fakeAgent();
    config(['ripe.http.allowed_origins' => ['https://ripe.example.test']]);
});

function plantWith(array $headers): TestResponse
{
    return test()->withHeaders(['Accept' => 'application/json', ...$headers])->postJson('/api/tasks', ['prompt' => 'Write the thank-you note.']);
}

it('accepts a change only with proof that it comes from Ripe', function (array $headers, int $status): void {
    plantWith($headers)->assertStatus($status);

    expect(Task::query()->count())->toBe($status === 201 ? 1 : 0);
})->with([
    'same-origin fetch metadata' => [['Sec-Fetch-Site' => 'same-origin'], 201],
    'same-origin with an allowed origin' => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://ripe.example.test'], 201],
    'same-origin with its own origin' => fn () => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => rtrim(url('/'), '/')], 201],
    'same-origin contradicted by a foreign origin' => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://evil.example'], 403],
    'same-site from an allowed origin' => [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://ripe.example.test'], 201],
    'same-site from a sibling origin' => [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://blog.example.test'], 403],
    'same-site without an origin' => [['Sec-Fetch-Site' => 'same-site'], 403],
    'cross-site even from an allowed origin' => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://ripe.example.test'], 403],
    'a typed address or bookmark' => [['Sec-Fetch-Site' => 'none'], 403],
    'an older browser with an allowed origin' => [['Origin' => 'https://ripe.example.test'], 201],
    'an older browser with its own origin' => fn () => [['Origin' => rtrim(url('/'), '/')], 201],
    'an older browser with a foreign origin' => [['Origin' => 'https://evil.example'], 403],
    'an opaque origin' => [['Origin' => 'null'], 403],
    'neither header' => [[], 403],
]);

it('lets reads through without fetch metadata', function (): void {
    api(headers: [])->getJson('/api/garden')->assertOk();
    api(headers: [])->getJson('/api/agent')->assertOk();
});

it('explains a refused change without touching anything', function (): void {
    plantWith(['Sec-Fetch-Site' => 'cross-site'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Changes to Ripe must come from Ripe itself.');
});

it('checks the origin before the rate limit, so forged requests cannot spend a visitor quota', function (): void {
    config(['ripe.limits.mutations_per_minute' => 2]);
    [, $token] = visitor();
    $task = apiTask($token);

    foreach (range(1, 5) as $attempt) {
        api($token, ['Sec-Fetch-Site' => 'cross-site'])
            ->postJson("/api/tasks/{$task->id}/pause")
            ->assertForbidden();
    }

    api($token)->postJson("/api/tasks/{$task->id}/pause")->assertOk();
});

it('grants no cross-origin access, since Ripe is served from one origin', function (): void {
    api(headers: ['Origin' => 'https://evil.example'])->getJson('/api/garden')
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    api(headers: ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->options('/api/tasks')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
