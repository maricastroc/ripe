<?php

declare(strict_types=1);

use App\Enums\Inference\ErrorScope;
use App\Enums\Inference\FinishReason;
use App\Enums\Inference\ReasoningEffort;
use App\Enums\Inference\TimeoutKind;
use App\Exceptions\Inference\CallAborted;
use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelTimeout;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\TransportFailed;
use App\Exceptions\Inference\TransportTimeout;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\Message;
use App\Inference\ModelRequest;
use App\Inference\OutputSchema;
use App\Inference\Pricing\CostCalculator;
use App\Inference\Transport\Timeouts;
use App\Inference\Usage;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingSupervisor;
use Tests\Support\ReplayTransport;

const CONTRACT_TEXT = "## Thank-you note\n\nDear [[TBD: neighbour's name]],\n\nThank you for watering my plants while I was away. They look happier than ever.";

const CONTRACT_JSON = ['verdict' => 'almost', 'summary' => 'A warm note that only needs the names.', 'strengths' => ['Warm tone'], 'issues' => [], 'missing' => ["The neighbour's name"], 'nextActions' => ['Fill in the name']];

function contractDriver(string $driver, string $scenario): ModelDriver
{
    $timeouts = new Timeouts(connect: 2, firstByte: 30, idle: 30, total: 60);
    $replay = static fn (string $format) => match ($scenario) {
        'text' => ReplayTransport::fixture("{$format}/text.".($format === 'openai' ? 'sse' : 'ndjson')),
        'structured' => ReplayTransport::fixture("{$format}/structured.".($format === 'openai' ? 'sse' : 'ndjson')),
        'text cut by the limit' => ReplayTransport::fixture("{$format}/length-text.".($format === 'openai' ? 'sse' : 'ndjson')),
        'structured cut by the limit' => ReplayTransport::fixture("{$format}/length-structured.".($format === 'openai' ? 'sse' : 'ndjson')),
        'unfinished stream' => ReplayTransport::fixture("{$format}/truncated.".($format === 'openai' ? 'sse' : 'ndjson')),
        'unreachable' => ReplayTransport::failing(new TransportFailed('Connection refused', 7)),
        'silent model' => ReplayTransport::failing(new TransportTimeout(TimeoutKind::Idle, 2000), "{$format}/text.".($format === 'openai' ? 'sse' : 'ndjson'), 2000),
        'unknown model' => ReplayTransport::error(404, '{"error":{"message":"The model does not exist."}}'),
        'aborted' => ReplayTransport::fixture("{$format}/text.".($format === 'openai' ? 'sse' : 'ndjson')),
    };

    return match ($driver) {
        'openai' => new OpenAIDriver($replay('openai'), app(HttpFactory::class), new CostCalculator, $timeouts, 'sk-test', 'gpt-test', defaultEffort: ReasoningEffort::Low),
        'ollama' => new OllamaDriver($replay('ollama'), app(HttpFactory::class), $timeouts, 'gemma4:e4b'),
        'fake' => FakeDriver::replying(match ($scenario) {
            'text' => FakeReply::text(CONTRACT_TEXT, usage: new Usage(1200, 180)),
            'structured' => FakeReply::json(CONTRACT_JSON, new Usage(900, 60)),
            'text cut by the limit' => FakeReply::text(mb_substr(CONTRACT_TEXT, 0, 40), FinishReason::Length),
            'structured cut by the limit' => FakeReply::text('{"verdict":"almost","summary":', FinishReason::Length),
            'unfinished stream' => FakeReply::partialThenFailure(mb_substr(CONTRACT_TEXT, 0, 50), new ModelCallFailed('The stream ended early.')),
            'unreachable' => FakeReply::failure(new ModelUnavailable('Unreachable.')),
            'silent model' => FakeReply::partialThenFailure(mb_substr(CONTRACT_TEXT, 0, 50), new ModelTimeout(TimeoutKind::Idle)),
            'unknown model' => FakeReply::failure(new DriverMisconfigured('Unknown model.')),
            'aborted' => FakeReply::text(CONTRACT_TEXT),
        }),
    };
}

function contractRequest(bool $structured): ModelRequest
{
    return new ModelRequest('System.', [Message::user('Write the note.')], 2200, $structured ? new OutputSchema('review', ['type' => 'object']) : null);
}

beforeEach(fn () => Http::fake(['*' => Http::response(['capabilities' => ['completion']])]));

dataset('drivers', ['openai', 'ollama', 'fake']);

it('returns the same text from every driver', function (string $driver): void {
    $response = contractDriver($driver, 'text')->generate(contractRequest(false));

    expect($response->text)->toBe(CONTRACT_TEXT)
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->estimated)->toBeFalse()
        ->and($response->usage->inputTokens)->toBeGreaterThan(0)
        ->and($response->driver)->toBe($driver);
})->with('drivers');

it('returns the same structured output from every driver', function (string $driver): void {
    $response = contractDriver($driver, 'structured')->generate(contractRequest(true));

    expect(json_decode($response->text, true))->toBe(CONTRACT_JSON);
})->with('drivers');

it('keeps text cut by the limit in every driver', function (string $driver): void {
    expect(contractDriver($driver, 'text cut by the limit')->generate(contractRequest(false))->finishReason)->toBe(FinishReason::Length);
})->with('drivers');

it('fails in the same way and the same scope in every driver', function (string $driver, string $scenario, bool $structured, string $exception, ErrorScope $scope, bool $spent): void {
    try {
        contractDriver($driver, $scenario)->generate(contractRequest($structured));
        $this->fail("Expected {$exception}.");
    } catch (InferenceException $failure) {
        expect($failure)->toBeInstanceOf($exception)
            ->and($failure->scope())->toBe($scope)
            ->and($failure->usage() !== null)->toBe($spent);
    }
})->with('drivers')->with([
    'structured cut by the limit' => ['structured cut by the limit', true, InvalidModelOutput::class, ErrorScope::Attempt, true],
    'unfinished stream' => ['unfinished stream', false, ModelCallFailed::class, ErrorScope::Attempt, true],
    'unreachable' => ['unreachable', false, ModelUnavailable::class, ErrorScope::Transient, false],
    'silent model' => ['silent model', false, ModelTimeout::class, ErrorScope::Attempt, true],
    'unknown model' => ['unknown model', false, DriverMisconfigured::class, ErrorScope::Driver, false],
]);

it('lets a supervisor abort any driver and reports what was already spent', function (string $driver): void {
    try {
        contractDriver($driver, 'aborted')->generate(contractRequest(false), new RecordingSupervisor(abortAfterTicks: 20));
        $this->fail('Expected the call to be aborted.');
    } catch (CallAborted $abort) {
        expect($abort->usage()?->estimated)->toBeTrue();
    }
})->with('drivers');
