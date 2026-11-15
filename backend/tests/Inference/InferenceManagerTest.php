<?php

declare(strict_types=1);

use App\Inference\Contracts\ModelDriver;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\InferenceManager;
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
