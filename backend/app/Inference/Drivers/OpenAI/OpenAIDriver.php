<?php

declare(strict_types=1);

namespace App\Inference\Drivers\OpenAI;

use App\Enums\Inference\FinishReason;
use App\Enums\Inference\ReasoningEffort;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRefused;
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
use App\Inference\Pricing\CostCalculator;
use App\Inference\Streaming\ServerSentEvents;
use App\Inference\Support\FirstTokenTimer;
use App\Inference\Support\Json;
use App\Inference\Transport\StreamRequest;
use App\Inference\Transport\StreamResponse;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use App\Inference\Usage;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;

final class OpenAIDriver implements ModelDriver
{
    private const int MESSAGE_OVERHEAD_TOKENS = 16;

    /**
     * @param  array<array-key, mixed>  $catalogue
     * @param  array<string, int>  $reasoningAllowance
     */
    public function __construct(
        private readonly StreamTransport $transport,
        private readonly HttpFactory $http,
        private readonly CostCalculator $costs,
        private readonly Timeouts $timeouts,
        private readonly ?string $apiKey,
        private readonly ?string $modelId,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly ReasoningEffort $defaultEffort = ReasoningEffort::Low,
        private readonly array $reasoningAllowance = [],
        private readonly array $catalogue = [],
        private readonly ?string $serviceTier = null,
        private readonly ?string $organization = null,
        private readonly ?string $project = null,
        private readonly OpenAIErrors $errors = new OpenAIErrors,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->modelId ?? '';
    }

    public function health(): DriverHealth
    {
        if ($this->apiKey === null || $this->apiKey === '' || $this->modelId === null || $this->modelId === '') {
            return DriverHealth::misconfigured('The OpenAI API key or model is not configured.');
        }

        try {
            $response = $this->http->withHeaders($this->identityHeaders())
                ->withToken($this->apiKey)
                ->timeout(5)
                ->get($this->url('/models/'.rawurlencode($this->modelId)));
        } catch (ConnectionException $exception) {
            return DriverHealth::unreachable("Couldn't reach OpenAI: {$exception->getMessage()}");
        }

        return match (true) {
            $response->successful() => DriverHealth::available([$this->modelId]),
            in_array($response->status(), [401, 403, 404], true) => DriverHealth::misconfigured("OpenAI rejected the key or the model {$this->modelId} (HTTP {$response->status()})."),
            default => DriverHealth::unreachable("OpenAI responded with HTTP {$response->status()}."),
        };
    }

    public function maximumCost(ModelRequest $request): ?int
    {
        $model = $this->resolveModel();

        if ($model->price === null) {
            return null;
        }

        $inputTokens = strlen($request->instructions) + self::MESSAGE_OVERHEAD_TOKENS * (count($request->messages) + 1);

        foreach ($request->messages as $message) {
            $inputTokens += strlen($message->content);
        }

        return $this->costs->ceiling($inputTokens, $this->maxOutputTokens($request, $model), $model->price);
    }

    public function generate(ModelRequest $request, ?CallSupervisor $supervisor = null, ?StreamObserver $observer = null): ModelResponse
    {
        $model = $this->resolveModel();
        $stream = new OpenAIStream;
        $timer = new FirstTokenTimer;
        $events = new ServerSentEvents;

        try {
            $response = $this->transport->send(
                new StreamRequest('POST', $this->url('/responses'), $this->headers(), $this->encode($this->payload($request, $model))),
                function (string $chunk) use ($events, $stream, $observer, $timer): void {
                    foreach ($events->push($chunk) as $event) {
                        $delta = $stream->apply($event);

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
            throw $this->withEstimate(new ModelTimeout($timeout->kind, $timeout), $request, $stream, $model);
        } catch (TransportFailed $failure) {
            $exception = new ModelUnavailable("Couldn't reach OpenAI: {$failure->getMessage()}", null, $failure);

            throw $failure->receivedBytes > 0 ? $this->withEstimate($exception, $request, $stream, $model) : $exception;
        } catch (CallAborted $abort) {
            throw $abort->withCallDetails(...$this->estimateDetails($request, $stream, $model));
        }

        if ($response->failed()) {
            throw $this->errors->fromHttp($response)->withCallDetails(null, $response->header('x-request-id'));
        }

        return $this->conclude($request, $model, $stream, $response, $timer);
    }

    private function conclude(ModelRequest $request, OpenAIModel $model, OpenAIStream $stream, StreamResponse $response, FirstTokenTimer $timer): ModelResponse
    {
        $requestId = $response->header('x-request-id');
        $usage = $this->reportedUsage($stream->response()) ?? $this->estimate($request, $stream);
        $cost = $model->price === null ? null : $this->costs->microdollars($usage, $model->price);
        $fail = static fn (InferenceException $exception): InferenceException => $exception->withCallDetails($usage, $requestId, $cost);

        $finish = match ($stream->terminal()) {
            'response.completed' => FinishReason::Stop,
            'response.incomplete' => $this->incompleteReason($request, $stream, $fail),
            'response.failed' => throw $fail($this->errors->fromFailedResponse($stream->response())),
            'error' => throw $fail($this->errors->fromStreamError($stream->error())),
            default => throw $fail(new ModelCallFailed('The stream ended before OpenAI finished the response.')),
        };

        $text = $stream->text();

        if ($text === '' && $stream->refusal() !== '') {
            throw $fail(new ModelRefused($stream->refusal()));
        }

        if (trim($text) === '') {
            throw $fail(new InvalidModelOutput('OpenAI returned an empty response.'));
        }

        return new ModelResponse(
            text: $text,
            finishReason: $finish,
            usage: $usage,
            driver: $this->name(),
            model: Json::string($stream->response(), 'model') ?? $model->id,
            costMicrodollars: $cost,
            requestId: $requestId,
            responseId: $stream->responseId(),
            timeToFirstTokenMs: $timer->milliseconds(),
            durationMs: $response->durationMs,
        );
    }

    /** @param Closure(InferenceException): InferenceException $fail */
    private function incompleteReason(ModelRequest $request, OpenAIStream $stream, Closure $fail): FinishReason
    {
        $reason = Json::string($stream->response(), 'incomplete_details.reason');

        if ($reason === 'content_filter') {
            throw $fail(new ModelRefused('OpenAI stopped the response with its content filter.'));
        }

        if ($reason !== 'max_output_tokens') {
            throw $fail(new ModelCallFailed("OpenAI left the response incomplete ({$reason})."));
        }

        if ($request->isStructured() || trim($stream->text()) === '') {
            throw $fail(new InvalidModelOutput('The response was cut by max_output_tokens before it was complete.'));
        }

        return FinishReason::Length;
    }

    /** @return array<string, mixed> */
    private function payload(ModelRequest $request, OpenAIModel $model): array
    {
        $payload = [
            'model' => $model->id,
            'instructions' => $request->instructions,
            'input' => array_map(static fn (Message $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ], $request->messages),
            'stream' => true,
            'store' => false,
            'max_output_tokens' => $this->maxOutputTokens($request, $model),
        ];

        if ($model->reasoning) {
            $payload['reasoning'] = ['effort' => ($request->reasoningEffort ?? $this->defaultEffort)->value];
        }

        if ($model->supportsTemperature && $request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->schema !== null) {
            $payload['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => $request->schema->name,
                'schema' => $request->schema->schema,
                'strict' => true,
            ]];
        }

        if ($request->safetyIdentifier !== null) {
            $payload['safety_identifier'] = $request->safetyIdentifier;
        }

        if ($this->serviceTier !== null && $this->serviceTier !== '') {
            $payload['service_tier'] = $this->serviceTier;
        }

        return $payload;
    }

    private function maxOutputTokens(ModelRequest $request, OpenAIModel $model): int
    {
        if (! $model->reasoning) {
            return $request->maxOutputTokens;
        }

        $effort = ($request->reasoningEffort ?? $this->defaultEffort)->value;

        return $request->maxOutputTokens + ($this->reasoningAllowance[$effort] ?? 0);
    }

    /** @param array<array-key, mixed> $response */
    private function reportedUsage(array $response): ?Usage
    {
        if (! is_array(Json::get($response, 'usage'))) {
            return null;
        }

        return new Usage(
            inputTokens: Json::integer($response, 'usage.input_tokens'),
            outputTokens: Json::integer($response, 'usage.output_tokens'),
            cachedInputTokens: Json::integer($response, 'usage.input_tokens_details.cached_tokens'),
            cacheWriteTokens: Json::integer($response, 'usage.input_tokens_details.cache_write_tokens'),
            reasoningTokens: Json::integer($response, 'usage.output_tokens_details.reasoning_tokens'),
        );
    }

    private function estimate(ModelRequest $request, OpenAIStream $stream): Usage
    {
        return Usage::estimate($request->inputCharacters(), $stream->streamedCharacters());
    }

    /** @return array{0: Usage, 1: null, 2: ?int} */
    private function estimateDetails(ModelRequest $request, OpenAIStream $stream, OpenAIModel $model): array
    {
        $usage = $this->estimate($request, $stream);

        return [$usage, null, $model->price === null ? null : $this->costs->microdollars($usage, $model->price)];
    }

    private function withEstimate(InferenceException $exception, ModelRequest $request, OpenAIStream $stream, OpenAIModel $model): InferenceException
    {
        return $exception->withCallDetails(...$this->estimateDetails($request, $stream, $model));
    }

    private function resolveModel(): OpenAIModel
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new DriverMisconfigured('The OpenAI API key is not configured.');
        }

        if ($this->modelId === null || $this->modelId === '') {
            throw new DriverMisconfigured('The OpenAI model is not configured.');
        }

        return OpenAIModel::fromCatalogue($this->modelId, $this->catalogue);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            ...$this->identityHeaders(),
        ];
    }

    /** @return array<string, string> */
    private function identityHeaders(): array
    {
        return array_filter([
            'OpenAI-Organization' => $this->organization ?? '',
            'OpenAI-Project' => $this->project ?? '',
        ], static fn (string $value): bool => $value !== '');
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
