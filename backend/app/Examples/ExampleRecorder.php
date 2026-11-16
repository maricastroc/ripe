<?php

declare(strict_types=1);

namespace App\Examples;

use App\Actions\Tasks\AnswerQuestion;
use App\Actions\Tasks\PlantTask;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\Question;
use App\Models\Task;
use App\Models\Visitor;
use App\Orchestration\Execution\RunExecutor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ExampleRecorder
{
    private const int MAX_ROUNDS = 80;

    public function __construct(
        private PlantTask $plant,
        private RunExecutor $executor,
        private AnswerQuestion $answers,
    ) {}

    /**
     * @param  array<string, string>  $answers
     * @param  Closure(CarbonImmutable): void  $waitUntil
     */
    public function grow(string $prompt, array $answers, Closure $waitUntil): Task
    {
        $gardener = Visitor::query()->create(['token_hash' => Visitor::hashToken(Str::random(64)), 'last_seen_at' => CarbonImmutable::now()]);
        $task = $this->plant->handle($gardener, $prompt);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $task->refresh();

            if ($task->status === TaskStatus::Ripe) {
                return $task;
            }

            if ($task->status === TaskStatus::Stalled) {
                throw new RuntimeException("The example stalled: {$task->stall_reason}");
            }

            foreach (Question::query()->where('task_id', $task->id)->where('kind', QuestionKind::Essential)->where('status', QuestionStatus::Open)->orderBy('position')->get() as $question) {
                $this->answers->handle($question, self::answerFor($question->text, $answers));
            }

            $run = AgentRun::query()->where('task_id', $task->id)->where('status', RunStatus::Queued)->orderBy('available_at')->orderBy('created_at')->first();

            if ($run === null) {
                continue;
            }

            $now = CarbonImmutable::now();

            if ($run->deferred_reason === RunDeferral::Quota && $run->available_at !== null && $run->available_at->greaterThan($now)) {
                $waitUntil($run->available_at);
            } elseif ($run->available_at !== null && $run->available_at->greaterThan($now)) {
                $run->wake($now);
            }

            $this->executor->execute($run->id);
        }

        throw new RuntimeException("The example did not ripen within {$round} rounds.");
    }

    /** @param array<string, string> $answers */
    private static function answerFor(string $question, array $answers): string
    {
        foreach ($answers as $topic => $answer) {
            if (preg_match('/'.str_replace('/', '\\/', $topic).'/iu', $question) === 1) {
                return $answer;
            }
        }

        throw new RuntimeException("No prepared answer for: {$question}");
    }
}
