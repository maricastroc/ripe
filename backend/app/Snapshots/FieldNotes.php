<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Enums\NoteKind;
use App\Enums\TaskEventType;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\Step;
use App\Models\TaskEvent;
use App\Observability\StallReason;
use Illuminate\Support\Collection;

final readonly class FieldNotes
{
    /** @var array<string, string> */
    private array $questions;

    /** @var array<string, string> */
    private array $steps;

    /** @var array<string, string> */
    private array $artifacts;

    /**
     * @param  Collection<int, Question>  $questions
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Artifact>  $artifacts
     */
    public function __construct(Collection $questions, Collection $steps, Collection $artifacts)
    {
        $this->questions = $questions->mapWithKeys(static fn (Question $question): array => [$question->id => $question->text])->all();
        $this->steps = $steps->mapWithKeys(static fn (Step $step): array => [$step->id => $step->title])->all();
        $this->artifacts = $artifacts->mapWithKeys(static fn (Artifact $artifact): array => [$artifact->id => $artifact->title])->all();
    }

    /**
     * @param  Collection<int, TaskEvent>  $events
     * @return list<FieldNote>
     */
    public function of(Collection $events): array
    {
        $notes = [];

        foreach ($events as $event) {
            $note = $this->describe($event);

            if ($note !== null && $event->occurred_at !== null) {
                $notes[] = new FieldNote($event->sequence, $note[0], $note[1], $event->occurred_at);
            }
        }

        return $notes;
    }

    /** @return array{NoteKind, string}|null */
    private function describe(TaskEvent $event): ?array
    {
        $payload = $event->payload;

        return match ($event->type) {
            TaskEventType::TaskPlanted => [NoteKind::User, __('Planted the seed.')],
            TaskEventType::TaskDeferred => [NoteKind::User, __('Left it to ripen.')],
            TaskEventType::TaskResumed => [NoteKind::User, __('Put it back to work.')],
            TaskEventType::TaskPaused => [NoteKind::User, __('Paused it.')],
            TaskEventType::TaskHarvested => [NoteKind::User, __('Harvested it.')],
            TaskEventType::TaskReplanted => [NoteKind::User, __('Replanted it.')],
            TaskEventType::TaskRenamed => [NoteKind::User, __('Renamed it “:title”.', ['title' => $this->text($payload, 'to')])],
            TaskEventType::TaskInterpreted => [NoteKind::Done, __('Understood the request.')],
            TaskEventType::TaskPlanned => [NoteKind::Done, $this->planned($payload)],
            TaskEventType::QuestionAsked => [NoteKind::Info, __('Asked: :question', ['question' => $this->name($this->questions, $payload, 'question_id')])],
            TaskEventType::QuestionAnswered => [NoteKind::User, __('Answered “:question”.', ['question' => $this->name($this->questions, $payload, 'question_id')])],
            TaskEventType::QuestionSkipped => [NoteKind::User, __('Skipped “:question”.', ['question' => $this->name($this->questions, $payload, 'question_id')])],
            TaskEventType::QuestionReopened => [NoteKind::User, __('Reopened “:question”.', ['question' => $this->name($this->questions, $payload, 'question_id')])],
            TaskEventType::StepCompleted => [NoteKind::Done, __('Finished “:step”.', ['step' => $this->name($this->steps, $payload, 'step_id')])],
            TaskEventType::ArtifactVersionCreated => $this->version($payload),
            TaskEventType::ReviewCompleted => [NoteKind::Done, __('Reviewed everything together.')],
            TaskEventType::RunRetryScheduled => [NoteKind::Warn, __('Something went wrong, so it will try again shortly.')],
            TaskEventType::RunInterrupted => [NoteKind::Warn, __('The work stopped halfway, so it will pick it up again.')],
            TaskEventType::BudgetDeferred => [NoteKind::Info, __('Resting until there is budget again.')],
            TaskEventType::QuotaDeferred => [NoteKind::Info, ($payload['wait'] ?? null) === 'daily' ? __('Resting until the free model has room again.') : __('Waiting a moment for the free model to have room.')],
            TaskEventType::TaskStatusChanged => $this->status($payload),
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private function planned(array $payload): string
    {
        $steps = is_array($payload['steps'] ?? null) ? count($payload['steps']) : 0;

        return trans_choice('Mapped out :count step.|Mapped out :count steps.', $steps);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{NoteKind, string}|null
     */
    private function version(array $payload): ?array
    {
        $title = $this->name($this->artifacts, $payload, 'artifact_id');

        return match ($payload['source'] ?? null) {
            'user_edit' => [NoteKind::User, __('Edited “:title”.', ['title' => $title])],
            'restore' => [NoteKind::User, __('Went back to an earlier version of “:title”.', ['title' => $title])],
            'revise' => [NoteKind::Done, __('Rewrote “:title”.', ['title' => $title])],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{NoteKind, string}|null
     */
    private function status(array $payload): ?array
    {
        return match ($payload['to'] ?? null) {
            'ripe' => [NoteKind::Done, __('Ripe. Everything is written and reviewed.')],
            'waiting' => [NoteKind::Info, __('Did everything it could for now and is waiting for you.')],
            'stalled' => [NoteKind::Error, __('Stalled: :reason', ['reason' => StallReason::present($this->text($payload, 'reason'))])],
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $payload
     */
    private function name(array $names, array $payload, string $key): string
    {
        $id = $payload[$key] ?? null;

        return is_string($id) && isset($names[$id]) ? $names[$id] : __('something that is gone');
    }

    /** @param array<string, mixed> $payload */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
