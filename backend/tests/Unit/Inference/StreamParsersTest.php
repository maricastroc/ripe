<?php

declare(strict_types=1);

use App\Inference\Streaming\JsonLines;
use App\Inference\Streaming\ServerSentEvent;
use App\Inference\Streaming\ServerSentEvents;

function sseFeed(string $stream, int $chunk): array
{
    $parser = new ServerSentEvents;
    $events = [];

    foreach (str_split($stream, $chunk) as $piece) {
        array_push($events, ...$parser->push($piece));
    }

    return array_map(static fn (ServerSentEvent $event): array => [$event->event, $event->data], $events);
}

it('reads the same events however the stream is cut', function (int $chunk): void {
    $stream = "event: response.created\ndata: {\"type\":\"response.created\"}\n\n: keep-alive\n\nevent: response.output_text.delta\r\ndata: {\"delta\":\"Olá, ação\"}\r\n\r\ndata: first\ndata: second\n\n";

    expect(sseFeed($stream, $chunk))->toBe([
        ['response.created', '{"type":"response.created"}'],
        ['response.output_text.delta', '{"delta":"Olá, ação"}'],
        [null, "first\nsecond"],
    ]);
})->with([1, 2, 3, 7, 64, 4096]);

it('notices an event that never ended', function (): void {
    $parser = new ServerSentEvents;
    $parser->push("event: response.output_text.delta\ndata: {\"delta\":\"x\"}");

    expect($parser->hasUnfinishedEvent())->toBeTrue();
});

it('splits newline-delimited JSON across arbitrary chunks', function (): void {
    $lines = new JsonLines;
    $read = [];

    foreach (str_split("{\"a\":1}\n\n{\"b\":\"ç\"}\n{\"c\":3}", 3) as $chunk) {
        array_push($read, ...$lines->push($chunk));
    }

    array_push($read, ...$lines->finish());

    expect($read)->toBe(['{"a":1}', '{"b":"ç"}', '{"c":3}']);
});
