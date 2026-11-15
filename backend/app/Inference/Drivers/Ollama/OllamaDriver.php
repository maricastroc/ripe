<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Ollama;

use App\Enums\Inference\FinishReason;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\TransportFailed;
use App\Exceptions\Inference\TransportTimeout;
use App\Inference\Contracts\CallSupervisor;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Contracts\StreamObserver;
use App\Inference\DriverHealth;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;
use App\Inference\NullSupervisor;
use App\Inference\Streaming\JsonLines;
use App\Inference\Support\FirstTokenTimer;
use App\Inference\Support\Json;
use App\Inference\Transport\StreamRequest;
use App\Inference\Transport\StreamResponse;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use App\Inference\Usage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;

final class OllamaDriver implements ModelDriver
{
    /** @var array<string, list<string>> */
    private array $capabilities = [];

    public function __construct(
        private readonly StreamTransport $transport,
        private readonly HttpFactory $http,
        private readonly Timeouts $timeouts,
        private readonly string $modelId,
        private readonly string $baseUrl = 'http://127.0.0.1:11434',
        private readonly string $keepAlive = '10m',
        private readonly int $contextWindow = 8192,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return $this->modelId;
    }

    public function health(): DriverHealth
    {
        try {
            $response = $this->http->timeout(3)->get($this->url('/api/tags'));
        } catch (ConnectionException) {
            return DriverHealth::unreachable("Couldn't reach Ollama at {$this->baseUrl}.");
        }

        if (! $response->successful()) {
            return DriverHealth::unreachable("Ollama responded with HTTP {$response->status()}.");
        }

        $models = [];

        foreach (Json::list($response->json(), 'models') as $entry) {
            $capabilities = Json::get($entry, 'capabilities');
            $name = Json::string($entry, 'name');

            if ($name !== null && (! is_array($capabilities) || in_array('completion', $capabilities, true))) {
                $models[] = $name;
            }
        }

        sort($models);

        if (! in_array($this->modelId, $models, true) && ! in_array("{$this->modelId}:latest", $models, true)) {
            return DriverHealth::misconfigured("Model {$this->modelId} is not installed in Ollama.", $models);
        }

        return DriverHealth::available($models);
    }

    public function maximumCost(ModelRequest $request): int
    {
        return 0;
    }

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse
    {
        $lines = new JsonLines;
        $stream = new OllamaStream;
        $timer = new FirstTokenTimer;

        try {
            $response = $this->transport->send(
                new StreamRequest('POST', $this->url('/api/chat'), ['Content-Type' => 'application/json'], $this->encode($this->payload($request))),
                function (string $chunk) use ($lines, $stream, $observer, $timer): void {
                    foreach ($lines->push($chunk) as $line) {
                        $delta = $stream->apply($line);

                        if ($delta !== null) {
                            $timer->tokenArrived();
                            $observer?->onDelta($delta);
                        }
                    }
                },
                $supervisor ?? new NullSupervisor,
                $this->timeouts,
            );
        } catch (TransportTimeout $timeout) {
            throw (new ModelTimeout($timeout->kind, $timeout))->withCallDetails($this->estimate($request, $stream), null, 0);
        } catch (TransportFailed $failure) {
            throw (new ModelUnavailable("Couldn't reach Ollama at {$this->baseUrl}: {$failure->getMessage()}", null, $failure))
                ->withCallDetails($failure->receivedBytes > 0 ? $this->estimate($request, $stream) : null, null, 0);
        } catch (CallAborted $abort) {
            throw $abort->withCallDetails($this->estimate($request, $stream), null, 0);
        }

        foreach ($lines->finish() as $line) {
            $stream->apply($line);
        }

        if ($response->failed()) {
            throw $this->fromHttp($response);
        }

        return $this->conclude($request, $stream, $response, $timer);
    }

    private function conclude(ModelRequest $request, OllamaStream $stream, StreamResponse $response, FirstTokenTimer $timer): ModelResponse
    {
        $usage = $stream->usage() ?? $this->estimate($request, $stream);
        $fail = static fn (InferenceException $exception): InferenceException => $exception->withCallDetails($usage, null, 0);

        if ($stream->error() !== null) {
            throw $fail(new ModelCallFailed("Ollama reported an error: {$stream->error()}"));
        }

        if (! $stream->isDone()) {
            throw $fail(new ModelCallFailed('The stream ended before Ollama finished the response.'));
        }

        $truncated = $stream->doneReason() === 'length';

        if ($truncated && $request->isStructured()) {
            throw $fail(new InvalidModelOutput('The response was cut by the output limit before it was complete.'));
        }

        if (trim($stream->text()) === '') {
            throw $fail(new InvalidModelOutput('Ollama returned an empty response.'));
        }

        return new ModelResponse(
            text: $stream->text(),
            finishReason: $truncated ? FinishReason::Length : FinishReason::Stop,
            usage: $usage,
            driver: $this->name(),
            model: $this->modelId,
            costMicrodollars: 0,
            requestId: null,
            responseId: null,
            timeToFirstTokenMs: $timer->milliseconds(),
            durationMs: $response->durationMs,
        );
    }

    private function fromHttp(StreamResponse $response): InferenceException
    {
        $message = Json::string(Json::decodeObject($response->errorBody), 'error') ?? "Ollama responded with HTTP {$response->status}.";

        return match (true) {
            $response->status === 404 => new DriverMisconfigured("Model {$this->modelId} is not available in Ollama: {$message}"),
            $response->status >= 400 && $response->status < 500 => new ModelRequestRejected($message),
            default => new ModelUnavailable($message),
        };
    }

    /** @return array<string, mixed> */
    private function payload(ModelRequest $request): array
    {
        $options = ['num_predict' => $request->maxOutputTokens, 'num_ctx' => $this->contextWindow];

        if ($request->temperature !== null) {
            $options['temperature'] = $request->temperature;
        }

        $payload = [
            'model' => $this->modelId,
            'messages' => [
                ['role' => 'system', 'content' => $request->instructions],
                ...array_map(static fn (Message $message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                ], $request->messages),
            ],
            'stream' => true,
            'keep_alive' => $this->keepAlive,
            'options' => $options,
        ];

        if ($request->schema !== null) {
            $payload['format'] = $request->schema->schema;
        }

        if (in_array('thinking', $this->capabilities(), true)) {
            $payload['think'] = false;
        }

        return $payload;
    }

    /** @return list<string> */
    private function capabilities(): array
    {
        if (array_key_exists($this->modelId, $this->capabilities)) {
            return $this->capabilities[$this->modelId];
        }

        try {
            $response = $this->http->timeout(5)->post($this->url('/api/show'), ['model' => $this->modelId]);
            $capabilities = $response->successful()
                ? array_values(array_filter(Json::list($response->json(), 'capabilities'), is_string(...)))
                : [];
        } catch (ConnectionException) {
            return [];
        }

        return $this->capabilities[$this->modelId] = $capabilities;
    }

    private function estimate(ModelRequest $request, OllamaStream $stream): Usage
    {
        return Usage::estimate($request->inputCharacters(), mb_strlen($stream->text()));
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ModelRequestRejected("The request could not be encoded: {$exception->getMessage()}");
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
