<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;

final class RecordingBroadcaster extends Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public int $calls = 0;

    public bool $failing = false;

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return null;
    }

    /** @param array<string, mixed> $payload */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $this->calls++;

        if ($this->failing) {
            throw new BroadcastException('Reverb is not answering.');
        }

        $this->sent[] = [
            'channels' => array_values(array_map(static fn (mixed $channel): string => is_string($channel) ? $channel : (string) json_encode($channel), $channels)),
            'event' => (string) $event,
            'payload' => $payload,
        ];
    }

    /** @return list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public function of(string $event): array
    {
        return array_values(array_filter($this->sent, static fn (array $sent): bool => $sent['event'] === $event));
    }
}
