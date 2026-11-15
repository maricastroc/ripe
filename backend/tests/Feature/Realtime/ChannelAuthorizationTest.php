<?php

declare(strict_types=1);

use App\Examples\ExampleGarden;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function joinChannel(?string $token, string $channel, array $headers = ['Sec-Fetch-Site' => 'same-origin'], string $socket = '1234.5678'): TestResponse
{
    return api($token, $headers)->postJson('/api/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel]);
}

it('lets a visitor join only the channels of what they may see, through the site', function (): void {
    fakeAgent();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'ripe-test',
        'broadcasting.connections.reverb.secret' => 'a-test-secret',
        'broadcasting.connections.reverb.app_id' => 'ripe',
    ]);
    require base_path('routes/channels.php');
    [$owner, $token] = visitor();
    [, $strangerToken] = visitor();
    $task = apiTask($token);
    $example = app(ExampleGarden::class)->ensure();

    expect(joinChannel($token, "private-task.{$task->id}")->assertOk()->json('auth'))
        ->toBe('ripe-test:'.hash_hmac('sha256', "1234.5678:private-task.{$task->id}", 'a-test-secret'));
    joinChannel($token, "private-greenhouse.{$owner->id}")->assertOk();
    joinChannel($strangerToken, "private-task.{$example->id}")->assertOk();

    $foreign = joinChannel($strangerToken, "private-task.{$task->id}")->assertForbidden()->json('message');
    $unknown = joinChannel($token, 'private-task.'.Str::uuid())->assertForbidden()->json('message');
    expect($unknown)->toBe($foreign);

    foreach ([
        [$strangerToken, "private-greenhouse.{$owner->id}"],
        [null, "private-task.{$task->id}"],
        [null, "private-greenhouse.{$owner->id}"],
        [null, "private-task.{$example->id}"],
        [$token, 'private-greenhouse.'.Str::uuid()],
        [$token, 'private-greenhouse.'.strtoupper($owner->id)],
        [$token, 'private-task.not-a-task'],
        [$token, "private-task.{$task->id}'--"],
        [$token, 'private-agent'],
        [$token, "private-task.{$task->id}.extra"],
    ] as [$who, $channel]) {
        joinChannel($who, $channel)->assertForbidden();
    }

    joinChannel($token, "presence-task.{$task->id}")->assertUnprocessable();
    joinChannel($token, "task.{$task->id}")->assertUnprocessable();
    joinChannel($token, "private-task.{$task->id}", socket: 'not-a-socket')->assertUnprocessable();

    joinChannel($token, "private-task.{$task->id}", ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    joinChannel($token, "private-task.{$task->id}", ['Origin' => 'https://elsewhere.example'])->assertForbidden();

    config(['ripe.gateway.required' => true, 'ripe.gateway.secret' => 'a-shared-gateway-secret']);
    joinChannel($token, "private-task.{$task->id}")->assertForbidden();
    joinChannel($token, "private-task.{$task->id}", ['Sec-Fetch-Site' => 'same-origin', 'X-Ripe-Gateway' => 'a-shared-gateway-secret'])->assertOk();
});
