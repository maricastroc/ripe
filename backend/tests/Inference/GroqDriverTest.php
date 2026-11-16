<?php

declare(strict_types=1);

use App\Enums\ChargeBasis;
use App\Enums\Inference\FinishReason;
use App\Enums\Inference\ReasoningEffort;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\RateLimited;
use App\Inference\Contracts\StreamObserver;
use App\Inference\Drivers\Groq\GroqDriver;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use App\Orchestration\AttemptReport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReplayTransport;

const GROQ_CATALOGUE = [
    'openai/gpt-oss-120b' => [
        'reasoning' => true,
        'supports_temperature' => true,
        'reasoning_allowance' => ['low' => 1024, 'medium' => 4096, 'high' => 8192],
        'limits' => ['requests_per_minute' => 30, 'requests_per_day' => 1000, 'tokens_per_minute' => 8000, 'tokens_per_day' => 200000, 'concurrency' => 1],
    ],
];

function groq(StreamTransport $transport, string $model = 'openai/gpt-oss-120b', ?string $key = 'gsk-test'): GroqDriver
{
    return new GroqDriver(
        transport: $transport,
        http: app(HttpFactory::class),
        timeouts: new Timeouts(connect: 5, firstByte: 30, idle: 60, total: 120),
        apiKey: $key,
        modelId: $model,
        defaultEffort: ReasoningEffort::Low,
        catalogue: GROQ_CATALOGUE,
    );
}

function groqRequest(?OutputSchema $schema = null): ModelRequest
{
    return new ModelRequest('You are Ripe\'s agent.', [Message::user('Write the note.')], 2200, $schema, 0.6, null, 'ripe_hashed_visitor');
}

function groqSchema(): OutputSchema
{
    return new OutputSchema('interpretation', [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => ['title' => ['type' => 'string'], 'outcome' => ['type' => 'string']],
        'required' => ['title', 'outcome'],
    ]);
}

final class GroqDeltas implements StreamObserver
{
    public string $text = '';

    public function onDelta(string $delta): void
    {
        $this->text .= $delta;
    }
}

it('asks for strict structured output in one plain request and reads the answer and its usage', function (): void {
    $transport = ReplayTransport::fixture('groq/structured.json');
    $response = groq($transport)->generate(groqRequest(groqSchema()));

    expect($transport->lastRequest?->url)->toBe('https://api.groq.com/openai/v1/chat/completions')
        ->and($transport->lastRequest?->headers)->toMatchArray(['Authorization' => 'Bearer gsk-test', 'Accept' => 'application/json'])
        ->and($transport->payload())->toBe([
            'model' => 'openai/gpt-oss-120b',
            'messages' => [
                ['role' => 'system', 'content' => 'You are Ripe\'s agent.'],
                ['role' => 'user', 'content' => 'Write the note.'],
            ],
            'max_completion_tokens' => 2200 + 1024,
            'stream' => false,
            'reasoning_effort' => 'low',
            'include_reasoning' => false,
            'temperature' => 0.6,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'interpretation', 'strict' => true, 'schema' => groqSchema()->schema]],
            'user' => 'ripe_hashed_visitor',
        ])
        ->and(json_decode($response->text, true))->toHaveKeys(['title', 'outcome'])
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->inputTokens)->toBe(150)
        ->and($response->usage->outputTokens)->toBe(81)
        ->and($response->usage->reasoningTokens)->toBe(19)
        ->and($response->usage->estimated)->toBeFalse()
        ->and($response->requestId)->toBe('req_replay')
        ->and($response->costMicrodollars)->toBe(0)
        ->and($response->driver)->toBe('groq');
});

it('streams text and takes the usage Groq sends at the end, wherever it puts it', function (string $fixture): void {
    $transport = ReplayTransport::fixture($fixture);
    $deltas = new GroqDeltas;
    $response = groq($transport)->generate(groqRequest(), null, $deltas);

    expect($transport->payload())->toMatchArray(['stream' => true, 'max_completion_tokens' => 2200 + 1024])
        ->and($transport->payload())->not->toHaveKey('response_format')
        ->and($deltas->text)->toStartWith('Thank you so much for taking care of my tomatoes')
        ->and($deltas->text)->toEndWith('helped produce!')
        ->and($response->text)->toBe($deltas->text)
        ->and($response->usage->inputTokens)->toBe(89)
        ->and($response->usage->outputTokens)->toBe(60)
        ->and($response->usage->estimated)->toBeFalse();
})->with(['groq/text.sse', 'groq/text-usage-groq.sse', 'groq/text-usage-top.sse']);

it('reports a cut answer as such and keeps the text streamed so far as an estimate', function (): void {
    $length = groq(ReplayTransport::fixture('groq/text-length.sse'))->generate(groqRequest());

    expect($length->finishReason)->toBe(FinishReason::Length)
        ->and(fn () => groq(ReplayTransport::fixture('groq/structured-length.json'))->generate(groqRequest(groqSchema())))->toThrow(InvalidModelOutput::class);

    foreach (['groq/text-truncated.sse', 'groq/stream-error.sse'] as $fixture) {
        try {
            groq(ReplayTransport::fixture($fixture))->generate(groqRequest());
            $this->fail("{$fixture} should have failed.");
        } catch (ModelCallFailed $failed) {
            expect($failed->usage()?->estimated)->toBeTrue()
                ->and(AttemptReport::fromFailure($failed)->billing())->not->toBe(ChargeBasis::Unbilled);
        }
    }
});

it('maps Groq errors to what Ripe does next, and counts a request as used only when Groq processed it', function (int $status, array $body, array $headers, string $class, ?float $retry, bool $daily, ChargeBasis $basis): void {
    try {
        groq(ReplayTransport::error($status, (string) json_encode($body), ['x-request-id' => 'req_error', ...$headers]))->generate(groqRequest());
        $this->fail('The request should have failed.');
    } catch (InferenceException $error) {
        expect($error)->toBeInstanceOf($class)
            ->and($error->requestId())->toBe('req_error')
            ->and($error->getMessage())->not->toContain('org_')->not->toContain('Upgrade')
            ->and(AttemptReport::fromFailure($error)->billing())->toBe($basis);

        if ($error instanceof RateLimited) {
            expect($error->retryAfter())->toBe($retry)->and($error->daily)->toBe($daily);
        }
    }
})->with([
    'a rejected key' => [401, ['error' => ['message' => 'Invalid API Key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], [], DriverMisconfigured::class, null, false, ChargeBasis::Unbilled],
    'a model the account cannot use' => [403, ['error' => ['message' => 'Forbidden']], [], DriverMisconfigured::class, null, false, ChargeBasis::Unbilled],
    'an unknown model' => [404, ['error' => ['message' => 'The model `openai/gpt-oss-999b` does not exist or you do not have access to it.', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], [], DriverMisconfigured::class, null, false, ChargeBasis::Unbilled],
    'a request too large' => [413, ['error' => ['message' => 'Request Entity Too Large']], [], ModelRequestRejected::class, null, false, ChargeBasis::Unbilled],
    'an answer that failed' => [422, ['error' => ['message' => 'Unprocessable']], [], InvalidModelOutput::class, null, false, ChargeBasis::Reservation],
    'the per-minute limit' => [429, ['error' => ['message' => 'Rate limit reached for model `openai/gpt-oss-120b` in organization `org_test` service tier `on_demand` on requests per minute (RPM): Limit 30, Used 30, Requested 1. Please try again in 2s. Need more tokens? Upgrade to Dev Tier today at https://console.groq.com/settings/billing', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], ['retry-after' => '2'], RateLimited::class, 2.0, false, ChargeBasis::Unbilled],
    'the daily limit' => [429, ['error' => ['message' => 'Rate limit reached for model `openai/gpt-oss-120b` in organization `org_test` service tier `on_demand` on tokens per day (TPD): Limit 200000, Used 199500, Requested 900. Please try again in 6m28.8s.', 'type' => 'tokens', 'code' => 'rate_limit_exceeded']], ['retry-after' => '389'], RateLimited::class, 389.0, true, ChargeBasis::Unbilled],
    'the flex tier at capacity' => [498, ['error' => ['message' => 'Flex tier capacity exceeded']], [], RateLimited::class, null, false, ChargeBasis::Unbilled],
    'an answer that failed strict validation' => [400, ['error' => ['message' => "Failed to validate JSON. Please adjust your prompt. See 'failed_generation' for more details.", 'type' => 'invalid_request_error', 'code' => 'json_validate_failed', 'failed_generation' => '']], [], InvalidModelOutput::class, null, false, ChargeBasis::Reservation],
    'a schema the answer missed' => [400, ['error' => ['message' => 'Generated JSON does not match the expected schema. Please adjust your prompt.']], [], InvalidModelOutput::class, null, false, ChargeBasis::Reservation],
    'a malformed request' => [400, ['error' => ['message' => 'logprobs is not supported']], [], ModelRequestRejected::class, null, false, ChargeBasis::Unbilled],
    'a server outage' => [503, ['error' => ['message' => 'Service Unavailable']], ['retry-after' => '30'], ModelUnavailable::class, null, false, ChargeBasis::Reservation],
]);

it('is free, declares its quota scope and demand, checks the model it uses, and refuses a model without known limits', function (): void {
    Http::fake(['https://api.groq.com/openai/v1/models/openai/gpt-oss-120b' => Http::response(['id' => 'openai/gpt-oss-120b']), '*' => Http::response([], 404)]);
    $driver = groq(ReplayTransport::fixture('groq/text.sse'));
    $request = groqRequest();

    expect($driver->health()->isAvailable())->toBeTrue()
        ->and($driver->isMetered())->toBeFalse()
        ->and($driver->maximumCost($request))->toBe(0)
        ->and($driver->quotaScope())->toBe('groq:openai/gpt-oss-120b')
        ->and($driver->quotaLimits()->tokensPerMinute)->toBe(8000)
        ->and($driver->tokenDemand($request))->toBeGreaterThan(2200 + 1024)
        ->and(fn () => groq(ReplayTransport::fixture('groq/text.sse'), 'llama-unknown')->quotaLimits())->toThrow(DriverMisconfigured::class)
        ->and(fn () => groq(ReplayTransport::fixture('groq/text.sse'), key: null)->generate($request))->toThrow(DriverMisconfigured::class);
});
