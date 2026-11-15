<?php

declare(strict_types=1);

use App\Enums\Inference\TimeoutKind;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\TransportFailed;
use App\Exceptions\Inference\TransportTimeout;
use App\Inference\NullSupervisor;
use App\Inference\Transport\CurlStreamTransport;
use App\Inference\Transport\StreamRequest;
use App\Inference\Transport\Timeouts;
use Tests\Support\RecordingSupervisor;
use Tests\Support\StreamServer;

beforeEach(fn () => StreamServer::start());
afterEach(fn () => StreamServer::stop());

function streamFrom(string $url, ?Timeouts $timeouts = null, ?RecordingSupervisor $supervisor = null, ?Closure $onBody = null): array
{
    $received = [];
    $response = (new CurlStreamTransport)->send(
        new StreamRequest('POST', $url, ['Content-Type' => 'application/json'], '{}'),
        $onBody ?? function (string $chunk) use (&$received): void {
            $received[] = $chunk;
        },
        $supervisor ?? new NullSupervisor,
        $timeouts ?? new Timeouts(connect: 2, firstByte: 5, idle: 5, total: 10),
    );

    return [$response, implode('', $received)];
}

it('delivers the body as it arrives and reports the response', function (): void {
    [$response, $body] = streamFrom(StreamServer::url(['chunks' => 3]));

    expect($response->status)->toBe(200)
        ->and($response->header('X-Request-Id'))->toBe('req_stream_server')
        ->and($response->firstByteMs)->not->toBeNull()
        ->and($body)->toBe("chunk-0\nchunk-1\nchunk-2\n");
});

it('keeps an error body for the driver instead of streaming it', function (): void {
    $streamed = false;
    [$response] = streamFrom(StreamServer::url(['status' => 429, 'chunks' => 2]), onBody: function () use (&$streamed): void {
        $streamed = true;
    });

    expect($response->failed())->toBeTrue()
        ->and($response->errorBody)->toBe("chunk-0\nchunk-1\n")
        ->and($streamed)->toBeFalse();
});

it('keeps supervising while the model is silent', function (): void {
    $supervisor = new RecordingSupervisor;
    $startedAt = microtime(true);

    [, $body] = streamFrom(StreamServer::url(['chunks' => 2, 'silence' => 2.0]), new Timeouts(connect: 2, firstByte: 5, idle: 5, total: 10), $supervisor);

    $elapsed = microtime(true) - $startedAt;

    expect($body)->toBe("chunk-0\nchunk-1\n")
        ->and($elapsed)->toBeGreaterThan(2.0)
        ->and($supervisor->longestGapBetween(0.3, 1.9))->toBeLessThan(0.6)
        ->and(count(array_filter($supervisor->ticks, static fn (float $tick): bool => $tick > 0.3 && $tick < 1.9)))->toBeGreaterThanOrEqual(5);
});

it('stops within a tick when the supervisor aborts during silence', function (): void {
    $startedAt = microtime(true);

    expect(fn () => streamFrom(StreamServer::url(['chunks' => 2, 'silence' => 5]), supervisor: new RecordingSupervisor(abortAfterSeconds: 1.0)))
        ->toThrow(CallAborted::class);

    expect(microtime(true) - $startedAt)->toBeLessThan(1.6);
});

it('gives up when the first byte takes too long', function (): void {
    $startedAt = microtime(true);

    try {
        streamFrom(StreamServer::url(['first' => 3]), new Timeouts(connect: 2, firstByte: 0.5, idle: 5, total: 10));
        $this->fail('Expected a first-byte timeout.');
    } catch (TransportTimeout $timeout) {
        expect($timeout->kind)->toBe(TimeoutKind::FirstByte)
            ->and($timeout->receivedBytes)->toBe(0)
            ->and(microtime(true) - $startedAt)->toBeLessThan(1.2);
    }
});

it('gives up when the stream goes quiet for too long', function (): void {
    try {
        streamFrom(StreamServer::url(['chunks' => 2, 'silence' => 3]), new Timeouts(connect: 2, firstByte: 2, idle: 0.5, total: 10));
        $this->fail('Expected an idle timeout.');
    } catch (TransportTimeout $timeout) {
        expect($timeout->kind)->toBe(TimeoutKind::Idle)
            ->and($timeout->receivedBytes)->toBe(strlen("chunk-0\n"));
    }
});

it('gives up when the whole call takes too long even while bytes keep coming', function (): void {
    try {
        streamFrom(StreamServer::url(['chunks' => 100, 'gap' => 0.1]), new Timeouts(connect: 2, firstByte: 0.5, idle: 0.5, total: 0.8));
        $this->fail('Expected a total timeout.');
    } catch (TransportTimeout $timeout) {
        expect($timeout->kind)->toBe(TimeoutKind::Total)
            ->and($timeout->receivedBytes)->toBeGreaterThan(0);
    }
});

it('reports a refused connection', function (): void {
    expect(fn () => streamFrom(StreamServer::closedPortUrl()))->toThrow(TransportFailed::class);
});

it('stops the transfer when the body handler fails', function (): void {
    expect(fn () => streamFrom(StreamServer::url(['chunks' => 50, 'gap' => 0.05]), onBody: function (): void {
        throw new RuntimeException('The parser rejected the stream.');
    }))->toThrow(RuntimeException::class, 'The parser rejected the stream.');
});
