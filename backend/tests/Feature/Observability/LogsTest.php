<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Inference\Drivers\Fake\FakeReply;
use App\Models\RunAttempt;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

function captureLogs(): TestHandler
{
    config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::setDefaultDriver('capture');
    $handler = Log::channel('capture')->getLogger()->getHandlers()[0] ?? null;

    return $handler instanceof TestHandler ? $handler : throw new RuntimeException('No test handler.');
}

it('logs every attempt with its task, run and attempt, and never the prompt, the answer, the cookie or query values', function (): void {
    $logs = captureLogs();
    fakeAgent(first: [static fn (): FakeReply => FakeReply::text('Not JSON at all: the secret quince ratio is 7 to 3.')]);
    [, $token] = visitor();
    $prompt = 'Ask Aunt Clementine for the quince jam recipe she has guarded since 1987.';

    api($token)->postJson('/api/tasks', ['prompt' => $prompt])->assertCreated();
    $task = ripen(Task::query()->sole());
    $refused = api($token)->postJson("/api/tasks/{$task->id}/resume")->assertConflict();
    report(new QueryException('pgsql', 'insert into tasks (prompt) values (?)', [$prompt], new PDOException("SQLSTATE[23505]: Unique violation: Key (prompt)=({$prompt}) already exists.")));

    $records = collect($logs->getRecords());
    $finished = $records->filter(static fn (LogRecord $record): bool => $record->message === 'Attempt finished.');
    $attempts = RunAttempt::query()->whereHas('run', static fn ($query) => $query->where('task_id', $task->id))->pluck('id')->sort()->values()->all();
    $refusal = $records->firstWhere('message', 'A request was refused.');
    $everything = $records->map(static fn (LogRecord $record): string => (string) json_encode([$record->message, $record->context, $record->extra]))->implode("\n");

    expect($task->status)->toBe(TaskStatus::Ripe)
        ->and($finished->map(static fn (LogRecord $record): mixed => $record->extra['attempt_id'] ?? null)->sort()->values()->all())->toBe($attempts)
        ->and($finished->every(static fn (LogRecord $record): bool => ($record->extra['task_id'] ?? null) === $task->id && $record->context['attempt_id'] === $record->extra['attempt_id']))->toBeTrue()
        ->and($finished->firstWhere('context.outcome', 'invalid_output')?->level)->toBe(Level::Warning)
        ->and($refusal?->level)->toBe(Level::Info)
        ->and($refusal?->extra['request_id'] ?? null)->toBe($refused->headers->get('X-Request-Id'))
        ->and($records->firstWhere('message', 'A database query failed.')?->context['sql'] ?? null)->toBe('insert into tasks (prompt) values (?)')
        ->and($everything)->not->toContain('Clementine')->not->toContain('quince')->not->toContain($token);
});
