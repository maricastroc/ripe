<?php

declare(strict_types=1);

use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('plants only once Cloudflare confirms the visitor, sends it the gateway address, and spends no allowance on a refusal', function (): void {
    fakeAgent();
    config([
        'ripe.turnstile.secret' => 'a-turnstile-secret',
        'ripe.gateway.required' => true,
        'ripe.gateway.secret' => 'a-shared-gateway-secret',
        'ripe.limits.plant_per_day_global' => 1,
    ]);
    Http::fake(['https://challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false, 'error-codes' => ['invalid-input-response']])->push(['success' => true])]);
    [, $token] = visitor();
    $plant = static fn (array $body) => api($token, [
        'Sec-Fetch-Site' => 'same-origin',
        'X-Ripe-Gateway' => 'a-shared-gateway-secret',
        'X-Ripe-Client-Ip' => '203.0.113.7',
    ])->postJson('/api/tasks', ['prompt' => 'A seed behind the check.', ...$body]);

    $plant([])->assertUnprocessable()->assertJsonValidationErrors('turnstile');
    $plant(['turnstile' => 'a-forged-token'])->assertUnprocessable()->assertJsonPath('errors.turnstile.0', 'The greenhouse could not confirm you are a person. Try again.');
    $plant(['turnstile' => 'a-real-token'])->assertCreated();

    Http::assertSent(static fn (Request $request): bool => $request['secret'] === 'a-turnstile-secret'
        && $request['response'] === 'a-real-token'
        && $request['remoteip'] === '203.0.113.7');
    expect(Task::query()->count())->toBe(1);
});
