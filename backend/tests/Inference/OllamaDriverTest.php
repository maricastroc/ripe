<?php

declare(strict_types=1);

use App\Enums\Inference\ErrorScope;
use App\Enums\Inference\HealthStatus;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\TransportFailed;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReplayTransport;

function ollama(StreamTransport $transport, string $model = 'gemma4:e4b'): OllamaDriver
{
    return new OllamaDriver($transport, app(HttpFactory::class), new Timeouts(connect: 2, firstByte: 30, idle: 30, total: 60), $model);
}

function ollamaRequest(bool $structured = false): ModelRequest
{
    return new ModelRequest('You are Ripe\'s agent.', [Message::user('Write the note.')], 2200, $structured ? new OutputSchema('review', ['type' => 'object']) : null, 0.6);
}

beforeEach(fn () => Http::fake([
    '127.0.0.1:11434/api/show' => Http::response(['capabilities' => ['completion', 'thinking']]),
    '127.0.0.1:11434/api/tags' => Http::response(['models' => [
        ['name' => 'gemma4:e4b', 'capabilities' => ['completion']],
        ['name' => 'embeddinggemma:latest', 'capabilities' => ['embedding']],
        ['name' => 'qwen3:latest'],
    ]]),
]));

it('keeps the local request exactly as the original agent sent it', function (): void {
    $transport = ReplayTransport::fixture('ollama/structured.ndjson');
    ollama($transport)->generate(ollamaRequest(structured: true));

    expect($transport->lastRequest?->url)->toBe('http://127.0.0.1:11434/api/chat')
        ->and($transport->payload())->toBe([
            'model' => 'gemma4:e4b',
            'messages' => [
                ['role' => 'system', 'content' => 'You are Ripe\'s agent.'],
                ['role' => 'user', 'content' => 'Write the note.'],
            ],
            'stream' => true,
            'keep_alive' => '10m',
            'options' => ['num_predict' => 2200, 'num_ctx' => 8192, 'temperature' => 0.6],
            'format' => ['type' => 'object'],
            'think' => false,
        ]);
});

it('maps local failures to their scopes', function (ReplayTransport $transport, string $exception, ErrorScope $scope): void {
    try {
        ollama($transport)->generate(ollamaRequest());
        $this->fail("Expected {$exception}.");
    } catch (InferenceException $failure) {
        expect($failure)->toBeInstanceOf($exception)->and($failure->scope())->toBe($scope);
    }
})->with([
    'Ollama is off' => [fn () => ReplayTransport::failing(new TransportFailed('Connection refused', 7)), ModelUnavailable::class, ErrorScope::Transient],
    'the model is not installed' => [fn () => ReplayTransport::error(404, '{"error":"model \"gemma9\" not found, try pulling it first"}'), DriverMisconfigured::class, ErrorScope::Driver],
    'a bad request' => [fn () => ReplayTransport::error(400, '{"error":"invalid format"}'), ModelRequestRejected::class, ErrorScope::Run],
    'a server error' => [fn () => ReplayTransport::error(500, '{"error":"out of memory"}'), ModelUnavailable::class, ErrorScope::Transient],
    'an error inside the stream' => [fn () => ReplayTransport::fixture('ollama/error.ndjson'), ModelCallFailed::class, ErrorScope::Attempt],
    'a stream that never finished' => [fn () => ReplayTransport::fixture('ollama/truncated.ndjson'), ModelCallFailed::class, ErrorScope::Attempt],
]);

it('reports which local models can write and whether the configured one is installed', function (): void {
    $health = ollama(new ReplayTransport)->health();

    expect($health->status)->toBe(HealthStatus::Available)
        ->and($health->models)->toBe(['gemma4:e4b', 'qwen3:latest'])
        ->and(ollama(new ReplayTransport, 'qwen3')->health()->isAvailable())->toBeTrue()
        ->and(ollama(new ReplayTransport, 'llama9')->health()->status)->toBe(HealthStatus::Misconfigured);
});
