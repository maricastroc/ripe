<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Groq;

use App\Enums\Inference\FinishReason;
use App\Enums\Inference\ReasoningEffort;
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
use App\Inference\Contracts\QuotaLimited;
use App\Inference\Contracts\StreamObserver;
use App\Inference\DriverHealth;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\ModelResponse;
use App\Inference\NullSupervisor;
use App\Inference\QuotaLimits;
use App\Inference\Streaming\ServerSentEvents;
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

final class GroqDriver implements ModelDriver, QuotaLimited
{
    private const int MESSAGE_OVERHEAD_TOKENS = 8;

    private const int CHARACTERS_PER_TOKEN = 3;

    /** @param array<array-key, mixed> $catalogue */
    public function __construct(
        private readonly StreamTransport $transport,
        private readonly HttpFactory $http,
        private readonly Timeouts $timeouts,
        private readonly ?string $apiKey,
        private readonly ?string $modelId,
        private readonly string $baseUrl = 'https://api.groq.com/openai/v1',
        private readonly ReasoningEffort $defaultEffort = ReasoningEffort::Low,
        private readonly array $catalogue = [],
        private readonly GroqErrors $errors = new GroqErrors,
    ) {}

    public function name(): string
    {
        return 'groq';
    }

    public function model(): string
    {
        return $this->modelId ?? '';
    }

    public function health(): DriverHealth
    {
        if ($this->apiKey === null || $this->apiKey === '' || $this->modelId === null || $this->modelId === '') {
            return DriverHealth::misconfigured('The Groq API key or model is not configured.');
        }

        try {
            $response = $this->http->withToken($this->apiKey)->timeout(5)->get($this->url('/models/'.implode('/', array_map(rawurlencode(...), explode('/', $this->modelId)))));
        } catch (ConnectionException $exception) {
            return DriverHealth::unreachable("Couldn't reach Groq: {$exception->getMessage()}");
        }

        return match (true) {
            $response->successful() => DriverHealth::available([$this->modelId]),
            in_array($response->status(), [401, 403, 404], true) => DriverHealth::misconfigured("Groq rejected the key or the model {$this->modelId} (HTTP {$response->status()})."),
            default => DriverHealth::unreachable("Groq responded with HTTP {$response->status()}."),
        };
    }

    public function isMetered(): bool
    {
        return false;
    }

    public function maximumCost(ModelRequest $request): int
    {
        return 0;
    }

    public function quotaScope(): string
    {
        return 'groq:'.$this->resolveModel()->id;
    }

    public function quotaLimits(): QuotaLimits
    {
        return $this->resolveModel()->limits;
    }

    public function tokenDemand(ModelRequest $request): int
    {
        return $this->inputEstimate($request) + $this->maxCompletionTokens($request, $this->resolveModel());
    }

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse
    {
        $model = $this->resolveModel();

        return $request->isStructured()
            ? $this->complete($request, $model, $supervisor ?? new NullSupervisor)
            : $this->stream($request, $model, $supervisor ?? new NullSupervisor, $observer);
    }

    private function complete(ModelRequest $request, GroqModel $model, CallSupervisor $supervisor): ModelResponse
    {
        $body = '';
        $timer = new FirstTokenTimer;

        try {
            $response = $this->transport->send(
                new StreamRequest('POST', $this->url('/chat/completions'), $this->headers('application/json'), $this->encode($this->payload($request, $model, stream: false))),
                function (string $chunk) use (&$body, $timer): void {
                    $timer->tokenArrived();
                    $body .= $chunk;
                },
                $supervisor,
                $this->timeouts,
            );
        } catch (TransportTimeout $timeout) {
            throw (new ModelTimeout($timeout->kind, $timeout))->withCallDetails(Usage::estimate($request->inputCharacters(), 0));
        } catch (TransportFailed $failure) {
            throw new ModelUnavailable("Couldn't reach Groq: {$failure->getMessage()}", null, $failure);
        } catch (CallAborted $abort) {
            throw $abort->withCallDetails(Usage::estimate($request->inputCharacters(), 0), null, 0);
        }

        if ($response->failed()) {
            throw $this->errors->fromHttp($response, Usage::estimate($request->inputCharacters(), 0));
        }

        $completion = Json::decodeObject($body) ?? [];
        $text = Json::string($completion, 'choices.0.message.content') ?? '';
        $usage = GroqUsage::from($completion) ?? Usage::estimate($request->inputCharacters(), mb_strlen($text));

        return $this->conclude($request, $model, $response, $timer, $text, Json::string($completion, 'choices.0.finish_reason'), $usage, Json::string($completion, 'model'), Json::string($completion, 'id'));
    }

    private function stream(ModelRequest $request, GroqModel $model, CallSupervisor $supervisor, ?StreamObserver $observer): ModelResponse
    {
        $stream = new GroqStream;
        $events = new ServerSentEvents;
        $timer = new FirstTokenTimer;

        try {
            $response = $this->transport->send(
                new StreamRequest('POST', $this->url('/chat/completions'), $this->headers('text/event-stream'), $this->encode($this->payload($request, $model, stream: true))),
                function (string $chunk) use ($events, $stream, $observer, $timer): void {
                    foreach ($events->push($chunk) as $event) {
                        $delta = $stream->apply($event);

                        if ($delta !== null) {
                            $timer->tokenArrived();
                            $observer?->onDelta($delta);
                        }
                    }
                },
                $supervisor,
                $this->timeouts,
            );
        } catch (TransportTimeout $timeout) {
            throw (new ModelTimeout($timeout->kind, $timeout))->withCallDetails($this->streamedUsage($request, $stream));
        } catch (TransportFailed $failure) {
            $exception = new ModelUnavailable("Couldn't reach Groq: {$failure->getMessage()}", null, $failure);

            throw $failure->receivedBytes > 0 ? $exception->withCallDetails($this->streamedUsage($request, $stream)) : $exception;
        } catch (CallAborted $abort) {
            throw $abort->withCallDetails($this->streamedUsage($request, $stream), null, 0);
        }

        if ($response->failed()) {
            throw $this->errors->fromHttp($response, Usage::estimate($request->inputCharacters(), 0));
        }

        $error = $stream->error();

        if ($error !== null) {
            throw $this->errors->fromStream($error)->withCallDetails($this->streamedUsage($request, $stream), $response->header('x-request-id'));
        }

        if (! $stream->finished()) {
            throw (new ModelCallFailed('The stream ended before Groq finished the answer.'))->withCallDetails($this->streamedUsage($request, $stream), $response->header('x-request-id'));
        }

        return $this->conclude($request, $model, $response, $timer, $stream->text(), $stream->finishReason(), $this->streamedUsage($request, $stream), $stream->model(), $stream->id());
    }

    private function conclude(ModelRequest $request, GroqModel $model, StreamResponse $response, FirstTokenTimer $timer, string $text, ?string $finish, Usage $usage, ?string $reportedModel, ?string $responseId): ModelResponse
    {
        $requestId = $response->header('x-request-id');
        $fail = static fn (InferenceException $exception): InferenceException => $exception->withCallDetails($usage, $requestId, 0);
        $reason = $finish === 'length' ? FinishReason::Length : FinishReason::Stop;

        if ($reason === FinishReason::Length && $request->isStructured()) {
            throw $fail(new InvalidModelOutput('The answer was cut by the output limit before it was complete.'));
        }

        if (trim($text) === '') {
            throw $fail(new InvalidModelOutput('Groq returned an empty answer.'));
        }

        return new ModelResponse(
            text: $text,
            finishReason: $reason,
            usage: $usage,
            driver: $this->name(),
            model: $reportedModel ?? $model->id,
            costMicrodollars: 0,
            requestId: $requestId,
            responseId: $responseId,
            timeToFirstTokenMs: $timer->milliseconds(),
            durationMs: $response->durationMs,
        );
    }

    /** @return array<string, mixed> */
    private function payload(ModelRequest $request, GroqModel $model, bool $stream): array
    {
        $payload = [
            'model' => $model->id,
            'messages' => [
                ['role' => 'system', 'content' => $request->instructions],
                ...array_map(static fn (Message $message): array => ['role' => $message->role->value, 'content' => $message->content], $request->messages),
            ],
            'max_completion_tokens' => $this->maxCompletionTokens($request, $model),
            'stream' => $stream,
        ];

        if ($model->reasoning) {
            $payload['reasoning_effort'] = $model->effort($request->reasoningEffort ?? $this->defaultEffort);
            $payload['include_reasoning'] = false;
        }

        if ($model->supportsTemperature && $request->temperature !== null) {
            $payload['temperature'] = max(0.01, $request->temperature);
        }

        if ($request->schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $request->schema->name, 'strict' => true, 'schema' => $request->schema->schema],
            ];
        }

        if ($request->safetyIdentifier !== null) {
            $payload['user'] = $request->safetyIdentifier;
        }

        return $payload;
    }

    private function maxCompletionTokens(ModelRequest $request, GroqModel $model): int
    {
        if (! $model->reasoning) {
            return $request->maxOutputTokens;
        }

        $effort = $model->effort($request->reasoningEffort ?? $this->defaultEffort);

        return $request->maxOutputTokens + ($model->reasoningAllowance[$effort] ?? 0);
    }

    private function inputEstimate(ModelRequest $request): int
    {
        $characters = mb_strlen($request->instructions);

        foreach ($request->messages as $message) {
            $characters += mb_strlen($message->content);
        }

        return intdiv($characters + self::CHARACTERS_PER_TOKEN - 1, self::CHARACTERS_PER_TOKEN) + self::MESSAGE_OVERHEAD_TOKENS * (count($request->messages) + 1);
    }

    private function streamedUsage(ModelRequest $request, GroqStream $stream): Usage
    {
        return $stream->usage() ?? Usage::estimate($request->inputCharacters(), $stream->streamedCharacters());
    }

    private function resolveModel(): GroqModel
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new DriverMisconfigured('The Groq API key is not configured.');
        }

        if ($this->modelId === null || $this->modelId === '') {
            throw new DriverMisconfigured('The Groq model is not configured.');
        }

        return GroqModel::fromCatalogue($this->modelId, $this->catalogue);
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];
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
