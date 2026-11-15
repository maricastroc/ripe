<?php

declare(strict_types=1);

use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use App\Inference\Transport\Timeouts;

it('rejects requests the providers would reject anyway', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'no messages' => fn () => new ModelRequest('System.', [], 100),
    'too few output tokens' => fn () => new ModelRequest('System.', [Message::user('Hi')], 8),
    'temperature out of range' => fn () => new ModelRequest('System.', [Message::user('Hi')], 100, temperature: 2.5),
    'oversized safety identifier' => fn () => new ModelRequest('System.', [Message::user('Hi')], 100, safetyIdentifier: str_repeat('x', 129)),
    'schema name with spaces' => fn () => new OutputSchema('my schema', []),
    'first byte after the deadline' => fn () => new Timeouts(connect: 1, firstByte: 20, idle: 5, total: 10),
]);

it('counts the characters sent to the model', function (): void {
    $request = new ModelRequest('Sistema', [Message::user('Olá'), Message::user('ação')], 100);

    expect($request->inputCharacters())->toBe(14);
});
