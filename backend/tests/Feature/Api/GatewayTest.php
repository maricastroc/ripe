<?php

declare(strict_types=1);

it('answers only through the gateway and limits by the address the gateway reports', function (): void {
    fakeAgent();
    config(['ripe.gateway.required' => true, 'ripe.gateway.secret' => 'a-shared-gateway-secret', 'ripe.limits.plant_per_hour_per_ip' => 1]);
    $through = static fn (string $address, array $extra = []) => api(headers: [
        'Sec-Fetch-Site' => 'same-origin',
        'X-Ripe-Gateway' => 'a-shared-gateway-secret',
        'X-Ripe-Client-Ip' => $address,
        ...$extra,
    ]);

    api()->getJson('/api/garden')->assertForbidden()->assertJsonPath('message', 'Ripe answers through its own site.');
    api(headers: ['X-Ripe-Gateway' => 'a-guessed-secret'])->getJson('/api/garden')->assertForbidden();

    $through('203.0.113.7')->postJson('/api/tasks', ['prompt' => 'A seed through the site.'])->assertCreated();
    $through('203.0.113.7', ['X-Forwarded-For' => '198.51.100.20'])->postJson('/api/tasks', ['prompt' => 'A seed with a forged address.'])->assertTooManyRequests();
    $through('198.51.100.20')->postJson('/api/tasks', ['prompt' => 'A seed from another network.'])->assertCreated();
});
