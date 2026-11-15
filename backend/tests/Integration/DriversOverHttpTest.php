<?php

declare(strict_types=1);

use App\Enums\Inference\ReasoningEffort;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\Pricing\CostCalculator;
use App\Inference\Transport\CurlStreamTransport;
use App\Inference\Transport\Timeouts;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\StreamServer;

beforeEach(fn () => StreamServer::start());
afterEach(fn () => StreamServer::stop());

it('reads a real OpenAI-shaped stream over HTTP', function (): void {
    $driver = new OpenAIDriver(new CurlStreamTransport, app(HttpFactory::class), new CostCalculator, new Timeouts(2, 5, 5, 10), 'sk-test', 'gpt-test', StreamServer::baseUrl().'/v1', ReasoningEffort::Low);

    $response = $driver->generate(new ModelRequest('System.', [Message::user('Write.')], 200));

    expect($response->text)->toStartWith('## Thank-you note')
        ->and($response->requestId)->toBe('req_stream_server')
        ->and($response->usage->inputTokens)->toBe(1200);
});

it('reads a real Ollama-shaped stream over HTTP', function (): void {
    Http::fake([StreamServer::baseUrl().'/api/show' => Http::response(['capabilities' => ['completion']])]);
    $driver = new OllamaDriver(new CurlStreamTransport, app(HttpFactory::class), new Timeouts(2, 5, 5, 10), 'gemma4:e4b', StreamServer::baseUrl());

    $response = $driver->generate(new ModelRequest('System.', [Message::user('Write.')], 200));

    expect($response->text)->toStartWith('## Thank-you note')
        ->and($response->usage->outputTokens)->toBe(180);
});
