<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\ModelRequest;
use App\Realtime\Publisher;
use App\Realtime\Signal;

it('relays live text through its own queue, with offsets that leave no gap', function (): void {
    $broadcasts = recordBroadcasts('realtime');
    $text = 'Olá, vizinha! 🌱 Obrigada por regar o manjericão e os tomates enquanto estive fora. Até já! 🍅';
    fakeAgent(first: [
        static fn (): ?FakeReply => null,
        static fn (): ?FakeReply => null,
        static fn (ModelRequest $request): ?FakeReply => $request->schema === null ? FakeReply::text($text) : null,
    ]);

    $task = ripen(plantTask());
    $streamed = collect(queuedSignals())->filter(static fn (Signal $signal): bool => $signal->event === Signal::OUTPUT_STREAMED)->groupBy(static fn (Signal $signal): string => (string) $signal->payload['attemptId']);

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($broadcasts->calls)->toBe(0)
        ->and($streamed)->not->toBeEmpty();

    $joined = $streamed->map(static function ($signals) use ($task): string {
        $text = '';

        foreach ($signals as $signal) {
            expect($signal->channels)->toBe(["private-task.{$task->id}"])
                ->and($signal->payload['offset'])->toBe(mb_strlen($text))
                ->and($signal->payload['delta'])->not->toBe('');
            $text .= $signal->payload['delta'];
        }

        return $text;
    });

    expect($joined->first())->toBe($text);
});

it('costs one bounded attempt per pause while Reverb hangs or refuses, and resumes when it is back', function (): void {
    $hanging = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No socket for the hanging Reverb.');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($hanging, false), ':'), 1);
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'ripe-test',
        'broadcasting.connections.reverb.secret' => 'a-test-secret',
        'broadcasting.connections.reverb.app_id' => 'ripe',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => $port,
        'broadcasting.connections.reverb.options.timeout' => 0.5,
        'broadcasting.connections.reverb.client_options.timeout' => 0.5,
    ]);
    $publisher = app(Publisher::class);
    $signal = static fn (): Signal => Signal::outputStreamed('01a1e000-0000-7000-8000-000000000001', 'run', 'attempt', 1, 0, 'Some words.');
    $timed = static function (Closure $work): array {
        $started = microtime(true);
        $result = $work();

        return [$result, microtime(true) - $started];
    };

    for ($window = 0; $window < 3; $window++) {
        [$probe, $probeTook] = $timed(static fn (): bool => $publisher->publish($signal()));
        [$sent, $restTook] = $timed(static fn (): int => array_sum(array_map(static fn (): int => (int) $publisher->publish($signal()), range(1, 200))));

        expect($probe)->toBeFalse()
            ->and($probeTook)->toBeGreaterThan(0.3)->toBeLessThan(1.5)
            ->and($sent)->toBe(0)
            ->and($restTook)->toBeLessThan(0.5);

        travelSeconds(16);
    }

    fclose($hanging);
    [$refused, $refusedTook] = $timed(static fn (): bool => $publisher->publish($signal()));
    expect($refused)->toBeFalse()->and($refusedTook)->toBeLessThan(1.0);

    $broadcasts = recordBroadcasts();
    expect($publisher->publish($signal()))->toBeFalse()
        ->and($broadcasts->calls)->toBe(0);

    travelSeconds(16);
    $late = $signal();
    expect($publisher->publish($signal()))->toBeTrue()
        ->and($broadcasts->sent)->toHaveCount(1);

    travelSeconds(6);
    expect($publisher->publish($late))->toBeFalse()
        ->and($broadcasts->calls)->toBe(1);
});
