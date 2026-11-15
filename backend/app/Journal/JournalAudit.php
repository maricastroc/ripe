<?php

declare(strict_types=1);

namespace App\Journal;

use App\Enums\AttemptOutcome;
use App\Enums\QuestionStatus;
use App\Enums\RunStatus;
use App\Enums\Stage;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Growth\GrowthCalculator;
use App\Models\AgentRun;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Support\Collection;

final class JournalAudit
{
    private const array ATTEMPT_EVENTS = [
        'succeeded' => [TaskEventType::RunSucceeded],
        'failed' => [TaskEventType::RunRetryScheduled, TaskEventType::RunFailed],
        'timed_out' => [TaskEventType::RunRetryScheduled, TaskEventType::RunFailed],
        'invalid_output' => [TaskEventType::RunRetryScheduled, TaskEventType::RunFailed],
        'driver_unavailable' => [TaskEventType::RunRetryScheduled, TaskEventType::RunFailed],
        'rate_limited' => [TaskEventType::RunRetryScheduled, TaskEventType::RunFailed],
        'cancelled' => [TaskEventType::RunCancelled],
        'lease_lost' => [TaskEventType::RunResultRejected, TaskEventType::RunInterrupted],
        'interrupted' => [TaskEventType::RunInterrupted],
    ];

    private const array TERMINAL_RUN_EVENTS = [
        'succeeded' => TaskEventType::RunSucceeded,
        'failed' => TaskEventType::RunFailed,
        'cancelled' => TaskEventType::RunCancelled,
    ];

    public function __construct(private readonly GrowthCalculator $growth) {}

    /** @return list<string> */
    public function problems(Task $task): array
    {
        $task->refresh();
        $events = TaskEvent::query()->where('task_id', $task->id)->orderBy('sequence')->get();

        return [
            ...$this->sequence($task, $events),
            ...$this->versions($task, $events),
            ...$this->attempts($task, $events),
            ...$this->runs($task, $events),
            ...$this->status($task, $events),
            ...$this->questions($task, $events),
            ...$this->steps($task, $events),
            ...$this->growth($task, $events),
        ];
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function sequence(Task $task, Collection $events): array
    {
        $sequences = $events->pluck('sequence')->all();

        return array_values(array_filter([
            $sequences !== range(1, max(1, count($sequences))) && $sequences !== [] ? 'the event sequence has gaps' : null,
            $task->version !== count($sequences) ? "the task version {$task->version} does not match ".count($sequences).' events' : null,
        ]));
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function versions(Task $task, Collection $events): array
    {
        $recorded = [];

        foreach ($events->where('type', TaskEventType::ArtifactVersionCreated) as $event) {
            $id = $event->payload['version_id'] ?? null;

            if (is_string($id)) {
                $recorded[$id] = ($recorded[$id] ?? 0) + 1;
            }
        }

        $problems = [];

        foreach (ArtifactVersion::query()->whereIn('artifact_id', $task->artifacts()->select('id'))->get() as $version) {
            if (($recorded[$version->id] ?? 0) !== 1) {
                $problems[] = "artifact version {$version->id} is recorded ".($recorded[$version->id] ?? 0).' times';
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function attempts(Task $task, Collection $events): array
    {
        $problems = [];
        $attempts = RunAttempt::query()->whereIn('run_id', AgentRun::query()->where('task_id', $task->id)->select('id'))->get();

        foreach ($attempts as $attempt) {
            $mine = $events->where('attempt_id', $attempt->id);

            if (! $mine->contains('type', TaskEventType::RunStarted)) {
                $problems[] = "attempt {$attempt->id} has no run.started event";
            }

            if (! $attempt->outcome instanceof AttemptOutcome) {
                continue;
            }

            $expected = self::ATTEMPT_EVENTS[$attempt->outcome->value];

            if (! $mine->contains(static fn (TaskEvent $event): bool => in_array($event->type, $expected, true))) {
                $problems[] = "attempt {$attempt->id} ended as {$attempt->outcome->value} without a matching event";
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function runs(Task $task, Collection $events): array
    {
        $problems = [];

        foreach (AgentRun::query()->where('task_id', $task->id)->get() as $run) {
            $mine = $events->where('run_id', $run->id);

            if (! $mine->contains('type', TaskEventType::RunScheduled)) {
                $problems[] = "run {$run->id} has no run.scheduled event";
            }

            $terminal = self::TERMINAL_RUN_EVENTS[$run->status->value] ?? null;

            if ($terminal !== null && $mine->where('type', $terminal)->count() !== 1) {
                $problems[] = "run {$run->id} is {$run->status->value} but has {$mine->where('type', $terminal)->count()} {$terminal->value} events";
            }

            if ($run->status === RunStatus::Succeeded && $mine->contains('type', TaskEventType::RunFailed)) {
                $problems[] = "run {$run->id} succeeded and failed";
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function status(Task $task, Collection $events): array
    {
        $last = $events->where('type', TaskEventType::TaskStatusChanged)->last();
        $to = $last?->payload['to'] ?? null;
        $recorded = $last === null ? TaskStatus::Dormant->value : (is_string($to) ? $to : 'unknown');

        return $recorded === $task->status->value ? [] : ["the task is {$task->status->value} but the journal says {$recorded}"];
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function questions(Task $task, Collection $events): array
    {
        $problems = [];
        $types = [
            TaskEventType::QuestionAsked->value => QuestionStatus::Open,
            TaskEventType::QuestionAnswered->value => QuestionStatus::Answered,
            TaskEventType::QuestionSkipped->value => QuestionStatus::Skipped,
            TaskEventType::QuestionReopened->value => QuestionStatus::Open,
        ];

        foreach (Question::query()->where('task_id', $task->id)->get() as $question) {
            $last = $events->filter(static fn (TaskEvent $event): bool => isset($types[$event->type->value]) && ($event->payload['question_id'] ?? null) === $question->id)->last();
            $recorded = $last === null ? null : ($types[$last->type->value] ?? null);

            if ($recorded !== $question->status) {
                $problems[] = "question {$question->id} is {$question->status->value} but the journal says ".($recorded->value ?? 'nothing');
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function steps(Task $task, Collection $events): array
    {
        $done = $task->steps()->where('status', StepStatus::Done)->count();
        $completed = $events->where('type', TaskEventType::StepCompleted)->count();

        return $done === $completed ? [] : ["{$done} steps are done but the journal records {$completed}"];
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<string>
     */
    private function growth(Task $task, Collection $events): array
    {
        $stage = $this->growth->forTask($task)->stage;
        $last = $events->where('type', TaskEventType::PlantStageChanged)->last();
        $to = $last?->payload['to'] ?? null;
        $recorded = $last === null ? Stage::Seed->value : (is_string($to) ? $to : 'unknown');

        return $recorded === $stage->value ? [] : ["the plant is {$stage->value} but the journal says {$recorded}"];
    }
}
