<?php

declare(strict_types=1);

use App\Facades\Inference;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\InferenceManager;
use App\Inference\Message;
use App\Inference\ModelRequest;
use Illuminate\Support\Facades\Config;

it('chooses the driver from configuration alone', function (string $name, string $class): void {
    Config::set('inference.driver', $name);
    app(InferenceManager::class)->forgetDrivers();

    expect(app(ModelDriver::class))->toBeInstanceOf($class);
})->with([
    ['openai', OpenAIDriver::class],
    ['ollama', OllamaDriver::class],
    ['fake', FakeDriver::class],
]);

it('rejects an unknown driver', function (): void {
    Config::set('inference.driver', 'mystery');
    app(InferenceManager::class)->forgetDrivers();

    expect(fn () => app(ModelDriver::class))->toThrow(InvalidArgumentException::class);
});

it('swaps every resolution for a fake in tests', function (): void {
    $fake = Inference::fake(FakeReply::text('From the fake.'));

    $response = app(ModelDriver::class)->generate(new ModelRequest('System.', [Message::user('Hi')], 100));

    expect($response->text)->toBe('From the fake.')
        ->and(app(ModelDriver::class))->toBe($fake)
        ->and($fake->sent())->toHaveCount(1);
});
