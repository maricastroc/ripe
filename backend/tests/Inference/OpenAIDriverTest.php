<?php

declare(strict_types=1);

use App\Enums\Inference\ErrorScope;
use App\Enums\Inference\FinishReason;
use App\Enums\Inference\ReasoningEffort;
use App\Enums\Inference\TimeoutKind;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRefused;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\ProviderQuotaExhausted;
use App\Exceptions\Inference\RateLimited;
use App\Exceptions\Inference\TransportFailed;
use App\Exceptions\Inference\TransportTimeout;
use App\Inference\Contracts\StreamObserver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use App\Inference\Pricing\CostCalculator;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingSupervisor;
use Tests\Support\ReplayTransport;

const OPENAI_CATALOGUE = [
    'gpt-test' => ['reasoning' => true, 'supports_temperature' => false, 'prices' => ['input' => 1.25, 'cached_input' => 0.125, 'output' => 10, 'checked_on' => '2026-11-15']],
    'gpt-plain' => ['reasoning' => false, 'supports_temperature' => true],
];

function openai(StreamTransport $transport, string $model = 'gpt-test', ?string $key = 'sk-test', array $extra = []): OpenAIDriver
{
    return new OpenAIDriver(...array_merge([
        'transport' => $transport,
        'http' => app(HttpFactory::class),
        'costs' => new CostCalculator,
        'timeouts' => new Timeouts(connect: 5, firstByte: 30, idle: 60, total: 120),
        'apiKey' => $key,
        'modelId' => $model,
        'defaultEffort' => ReasoningEffort::Low,
        'reasoningAllowance' => ['low' => 4096, 'medium' => 16384],
        'catalogue' => OPENAI_CATALOGUE,
    ], $extra));
}

function textRequest(?OutputSchema $schema = null, ?ReasoningEffort $effort = null): ModelRequest
{
    return new ModelRequest('You are Ripe\'s agent.', [Message::user('Write the note.')], 2200, $schema, 0.6, $effort, 'ripe_hashed_visitor');
}

function structuredRequest(): ModelRequest
{
    return textRequest(new OutputSchema('review', ['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => []]));
}

final class CollectingObserver implements StreamObserver
{
    public string $text = '';

    public function onDelta(string $delta): void
    {
        $this->text .= $delta;
    }
}

it('sends a private, streamed request with the reasoning budget on top of the visible one', function (): void {
    $transport = ReplayTransport::fixture('openai/text.sse');
    openai($transport, extra: ['serviceTier' => 'flex', 'organization' => 'org_1', 'project' => 'proj_1'])->generate(textRequest(null, ReasoningEffort::Medium));

    expect($transport->lastRequest?->url)->toBe('https://api.openai.com/v1/responses')
        ->and($transport->lastRequest?->headers)->toMatchArray([
            'Authorization' => 'Bearer sk-test',
            'Accept' => 'text/event-stream',
            'OpenAI-Organization' => 'org_1',
            'OpenAI-Project' => 'proj_1',
        ])
        ->and($transport->payload())->toBe([
            'model' => 'gpt-test',
            'instructions' => 'You are Ripe\'s agent.',
            'input' => [['role' => 'user', 'content' => 'Write the note.']],
            'stream' => true,
            'store' => false,
            'max_output_tokens' => 2200 + 16384,
            'reasoning' => ['effort' => 'medium'],
            'safety_identifier' => 'ripe_hashed_visitor',
            'service_tier' => 'flex',
        ]);
});

it('sends temperature only to models that accept it, and no reasoning to models without it', function (): void {
    $transport = ReplayTransport::fixture('openai/text.sse');
    openai($transport, 'gpt-plain')->generate(textRequest());

    expect($transport->payload())->toMatchArray(['max_output_tokens' => 2200, 'temperature' => 0.6])
        ->and($transport->payload())->not->toHaveKey('reasoning');
});

it('asks for strict structured output', function (): void {
    $transport = ReplayTransport::fixture('openai/structured.sse');
    openai($transport)->generate(structuredRequest());

    expect($transport->payload()['text'])->toBe(['format' => [
        'type' => 'json_schema',
        'name' => 'review',
        'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => []],
        'strict' => true,
    ]]);
});

it('streams the text, keeps the final one and accounts for every kind of token', function (): void {
    $observer = new CollectingObserver;
    $supervisor = new RecordingSupervisor;
    $response = openai(ReplayTransport::fixture('openai/text.sse'))->generate(textRequest(), $supervisor, $observer);

    expect($response->text)->toStartWith("## Thank-you note\n\nDear [[TBD: neighbour's name]]")
        ->and($observer->text)->toBe($response->text)
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->inputTokens)->toBe(1200)
        ->and($response->usage->cachedInputTokens)->toBe(1024)
        ->and($response->usage->outputTokens)->toBe(180)
        ->and($response->usage->reasoningTokens)->toBe(96)
        ->and($response->usage->estimated)->toBeFalse()
        ->and($response->costMicrodollars)->toBe(intdiv(176 * 1_250_000 + 1024 * 125_000 + 180 * 10_000_000 + 999_999, 1_000_000))
        ->and($response->requestId)->toBe('req_replay')
        ->and($response->responseId)->toBe('resp_text')
        ->and($response->timeToFirstTokenMs)->toBeGreaterThanOrEqual(0)
        ->and($response->model)->toBe('gpt-test-2026-06-01')
        ->and($supervisor->ticks)->not->toBeEmpty();
});

it('reports the cache-write tokens of a structured answer', function (): void {
    $response = openai(ReplayTransport::fixture('openai/structured.sse'))->generate(structuredRequest());

    expect(json_decode($response->text, true))->toHaveKey('verdict', 'almost')
        ->and($response->usage->cacheWriteTokens)->toBe(512);
});

it('maps every documented stream outcome to its scope', function (string $fixture, string $exception, ErrorScope $scope): void {
    try {
        openai(ReplayTransport::fixture($fixture))->generate(textRequest());
        $this->fail("Expected {$exception}.");
    } catch (InferenceException $failure) {
        expect($failure)->toBeInstanceOf($exception)
            ->and($failure->scope())->toBe($scope)
            ->and($failure->requestId())->toBe('req_replay');
    }
})->with([
    'a refusal' => ['openai/refusal.sse', ModelRefused::class, ErrorScope::Run],
    'the content filter' => ['openai/content-filter.sse', ModelRefused::class, ErrorScope::Run],
    'a server failure' => ['openai/failed-server.sse', ModelUnavailable::class, ErrorScope::Transient],
    'a rejected prompt' => ['openai/failed-prompt.sse', ModelRequestRejected::class, ErrorScope::Run],
    'a rate limit inside the stream' => ['openai/error-rate-limit.sse', RateLimited::class, ErrorScope::Transient],
    'a stream that never finished' => ['openai/truncated.sse', ModelCallFailed::class, ErrorScope::Attempt],
]);

it('estimates the usage when the provider never reported it', function (): void {
    try {
        openai(ReplayTransport::fixture('openai/truncated.sse'))->generate(textRequest());
    } catch (ModelCallFailed $failure) {
        expect($failure->usage()?->estimated)->toBeTrue()
            ->and($failure->usage()?->outputTokens)->toBeGreaterThan(0)
            ->and($failure->costMicrodollars())->toBeGreaterThan(0);
    }
});

it('maps every documented HTTP error to its scope', function (int $status, array $error, array $headers, string $exception, ErrorScope $scope, ?float $retryAfter): void {
    $transport = ReplayTransport::error($status, (string) json_encode(['error' => $error]), $headers + ['x-request-id' => 'req_error']);

    try {
        openai($transport)->generate(textRequest());
        $this->fail("Expected {$exception}.");
    } catch (InferenceException $failure) {
        expect($failure)->toBeInstanceOf($exception)
            ->and($failure->scope())->toBe($scope)
            ->and($failure->retryAfter())->toBe($retryAfter)
            ->and($failure->requestId())->toBe('req_error')
            ->and($failure->usage())->toBeNull();
    }
})->with([
    'a rate limit with Retry-After' => [429, ['type' => 'rate_limit_error', 'code' => 'slow_down', 'message' => 'Slow down.'], ['retry-after' => '7'], RateLimited::class, ErrorScope::Transient, 7.0],
    'a rate limit with reset headers' => [429, ['type' => 'requests', 'code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached.'], ['x-ratelimit-reset-requests' => '1m30s'], RateLimited::class, ErrorScope::Transient, 90.0],
    'exhausted credit' => [429, ['type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted', 'message' => 'No credit.'], [], ProviderQuotaExhausted::class, ErrorScope::Driver, null],
    'the organization spend limit' => [429, ['code' => 'organization_spend_limit_exceeded', 'message' => 'Limit.'], [], ProviderQuotaExhausted::class, ErrorScope::Driver, null],
    'the project spend limit' => [429, ['code' => 'project_spend_limit_exceeded', 'message' => 'Limit.'], [], ProviderQuotaExhausted::class, ErrorScope::Driver, null],
    'the usage limit' => [429, ['code' => 'organization_usage_limit_exceeded', 'message' => 'Limit.'], [], ProviderQuotaExhausted::class, ErrorScope::Driver, null],
    'an older quota error' => [429, ['type' => 'insufficient_quota', 'message' => 'Quota.'], [], ProviderQuotaExhausted::class, ErrorScope::Driver, null],
    'an invalid key' => [401, ['type' => 'invalid_request_error', 'message' => 'Incorrect API key provided.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'an unsupported region' => [403, ['message' => 'Country, region, or territory not supported.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'an unknown model' => [404, ['code' => 'model_not_found', 'message' => 'The model does not exist.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'a service tier the project cannot use' => [400, ['type' => 'invalid_request_error', 'param' => 'service_tier', 'message' => 'Invalid service_tier argument.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'a parameter the model rejects' => [400, ['type' => 'invalid_request_error', 'param' => 'temperature', 'code' => 'unsupported_parameter', 'message' => 'Unsupported.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'a broken schema' => [400, ['type' => 'invalid_request_error', 'param' => 'text.format.schema', 'message' => 'Invalid schema.'], [], DriverMisconfigured::class, ErrorScope::Driver, null],
    'an input that is too long' => [400, ['type' => 'invalid_request_error', 'param' => 'input', 'code' => 'context_length_exceeded', 'message' => 'Too long.'], [], ModelRequestRejected::class, ErrorScope::Run, null],
    'a server error' => [500, ['type' => 'server_error', 'message' => 'Oops.'], [], ModelUnavailable::class, ErrorScope::Transient, null],
    'an overloaded model' => [503, ['type' => 'service_unavailable_error', 'code' => 'server_is_overloaded', 'message' => 'Overloaded.'], ['retry-after' => '12'], ModelUnavailable::class, ErrorScope::Transient, 12.0],
]);

it('turns transport problems into scoped errors with an estimate of what was spent', function (): void {
    $timeout = new TransportTimeout(TimeoutKind::Idle, 3000);

    try {
        openai(ReplayTransport::failing($timeout, 'openai/text.sse', 3000))->generate(textRequest());
    } catch (ModelTimeout $failure) {
        expect($failure->kind)->toBe(TimeoutKind::Idle)
            ->and($failure->scope())->toBe(ErrorScope::Attempt)
            ->and($failure->usage()?->estimated)->toBeTrue()
            ->and($failure->usage()?->outputTokens)->toBeGreaterThan(0);
    }

    expect(fn () => openai(ReplayTransport::failing(new TransportFailed('Connection refused')))->generate(textRequest()))
        ->toThrow(ModelUnavailable::class);
});

it('caps the possible cost of a request before sending it', function (): void {
    $request = textRequest(null, ReasoningEffort::Medium);
    $driver = openai(ReplayTransport::fixture('openai/text.sse'));
    $ceiling = $driver->maximumCost($request);
    $actual = $driver->generate($request)->costMicrodollars;

    expect($ceiling)->toBeGreaterThan(0)
        ->and($ceiling)->toBeGreaterThanOrEqual((int) $actual)
        ->and(openai(ReplayTransport::fixture('openai/text.sse'), 'gpt-unpriced')->maximumCost($request))->toBeNull();
});

it('checks its health without spending tokens', function (): void {
    Http::fake([
        'api.openai.com/v1/models/gpt-test' => Http::response(['id' => 'gpt-test']),
        'api.openai.com/v1/models/gpt-gone' => Http::response(['error' => ['message' => 'Not found']], 404),
    ]);

    expect(openai(new ReplayTransport)->health()->isAvailable())->toBeTrue()
        ->and(openai(new ReplayTransport, 'gpt-gone')->health()->message)->toContain('gpt-gone')
        ->and(openai(new ReplayTransport, key: null)->health()->isAvailable())->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-test'));
});
