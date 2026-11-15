<?php

declare(strict_types=1);

use App\Actions\Tasks\PlantTask;
use App\Agent\Simulation\SimulatedModel;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Exceptions\Inference\InferenceException;
use App\Facades\Inference;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Fake\FakeReply;
use App\Inference\ModelRequest;
use App\Journal\JournalAudit;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Feature');
pest()->extend(TestCase::class)->in('Inference', 'Integration', 'Live');

function expectConstraintViolation(Closure $change, string $constraint): void
{
    try {
        $change();
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain("\"{$constraint}\"");

        return;
    }

    throw new AssertionFailedError("Expected {$constraint} to reject the change.");
}

function drainAgentQueue(int $limit = 200): int
{
    $processed = 0;

    while ($processed < $limit && ($job = Queue::connection('agent')->pop('agent')) !== null) {
        $job->fire();
        $processed++;
    }

    return $processed;
}

function ripen(Task $task, int $rounds = 200): Task
{
    for ($round = 0; $round < $rounds; $round++) {
        if (drainAgentQueue() > 0) {
            continue;
        }

        $next = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Queued)->min('available_at');

        if (! is_string($next)) {
            break;
        }

        Date::setTestNow(CarbonImmutable::parse($next)->addSecond());
    }

    return $task->refresh();
}

function interpretRun(Task $task): AgentRun
{
    return AgentRun::query()->where('task_id', $task->id)->where('kind', RunKind::Interpret)->sole();
}

function travelSeconds(float $seconds): void
{
    Date::setTestNow(CarbonImmutable::now()->addMilliseconds((int) ($seconds * 1000)));
}

function expectJournalConsistent(Task $task): void
{
    expect(app(JournalAudit::class)->problems($task))->toBe([]);
}

function plantTask(string $prompt = 'Write a short thank-you note to the neighbour who watered my plants.', bool $defer = true): Task
{
    return app(PlantTask::class)->handle(Visitor::factory()->create(), $prompt, $defer);
}

function question(string $text, string $kind = 'essential'): array
{
    return ['question' => $text, 'why' => 'It matters.', 'kind' => $kind];
}

function step(string $title, array $buildsOn = [], array $requires = []): array
{
    return ['title' => $title, 'description' => "{$title}.", 'output' => 'draft', 'buildsOn' => $buildsOn, 'requires' => $requires];
}

/**
 * @param  list<array<string, mixed>>  $questions
 * @param  list<array<string, mixed>>|null  $steps
 * @param  list<InferenceException|Closure(ModelRequest): ?FakeReply>  $first
 */
function fakeAgent(array $questions = [], ?array $steps = null, array $first = []): FakeDriver
{
    $simulated = new SimulatedModel;

    return Inference::fakeWith(FakeDriver::respondingWith(function (ModelRequest $request) use (&$first, $simulated, $questions, $steps): FakeReply {
        $scripted = array_shift($first);

        if ($scripted instanceof InferenceException) {
            return FakeReply::failure($scripted);
        }

        if ($scripted instanceof Closure && ($reply = $scripted($request)) !== null) {
            return $reply;
        }

        return match ($request->schema?->name) {
            'interpretation' => FakeReply::json([
                'title' => 'Reply to the client',
                'language' => 'en',
                'deliverable' => 'message',
                'outcome' => 'A clear reply.',
                'context' => [],
                'assumptions' => [],
                'questions' => $questions,
            ]),
            'plan' => $steps === null ? $simulated($request) : FakeReply::json(['steps' => $steps]),
            default => $simulated($request),
        };
    }));
}
