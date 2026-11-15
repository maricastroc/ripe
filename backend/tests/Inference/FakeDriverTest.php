<?php

declare(strict_types=1);

use App\Enums\Inference\FinishReason;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\RateLimited;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use Tests\Support\RecordingSupervisor;

function fakeRequest(string $text = 'Write.', bool $structured = false): ModelRequest
{
    return new ModelRequest('System.', [Message::user($text)], 100, $structured ? new OutputSchema('x', []) : null);
}

it('plays scripted replies in order and remembers what it was asked', function (): void {
    $fake = FakeDriver::replying(FakeReply::text('First answer.'), FakeReply::json(['ok' => true]));

    expect($fake->generate(fakeRequest('one'))->text)->toBe('First answer.')
        ->and($fake->generate(fakeRequest('two'))->text)->toBe('{"ok":true}')
        ->and(array_map(static fn (ModelRequest $request): string => $request->messages[0]->content, $fake->sent()))->toBe(['one', 'two'])
        ->and(fn () => $fake->generate(fakeRequest()))->toThrow(LogicException::class);
});

it('answers from a responder when no script is left', function (): void {
    $fake = FakeDriver::respondingWith(static fn (ModelRequest $request): FakeReply => FakeReply::text('Echo: '.$request->messages[0]->content));

    expect($fake->generate(fakeRequest('hello'))->text)->toBe('Echo: hello');
});

it('fails after streaming part of the answer, with an estimate of what was used', function (): void {
    $fake = FakeDriver::replying(FakeReply::partialThenFailure('Half of the ', new RateLimited('Slow down.', 3.0)));

    try {
        $fake->generate(fakeRequest());
    } catch (RateLimited $failure) {
        expect($failure->retryAfter())->toBe(3.0)
            ->and($failure->usage()?->estimated)->toBeTrue();
    }
});

it('stays silent for a while so supervisors can act', function (): void {
    $fake = FakeDriver::replying(FakeReply::text('Late answer.')->afterSilence(50));

    expect(fn () => $fake->generate(fakeRequest(), new RecordingSupervisor(abortAfterTicks: 10)))->toThrow(CallAborted::class);
});

it('rejects a cut structured answer like the real drivers do', function (): void {
    $fake = FakeDriver::replying(FakeReply::text('{"cut', FinishReason::Length));

    expect(fn () => $fake->generate(fakeRequest(structured: true)))->toThrow(InvalidModelOutput::class);
});
