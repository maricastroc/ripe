<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Enums\NoteKind;
use App\Enums\TaskEventType;
use App\Models\Artifact;
use App\Models\Question;
use App\Models\Step;
use App\Models\TaskEvent;
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
            TaskEventType::TaskPlanted => [NoteKind::User, 'Planted the seed.'],
            TaskEventType::TaskDeferred => [NoteKind::User, 'Left it to ripen.'],
            TaskEventType::TaskResumed => [NoteKind::User, 'Put it back to work.'],
            TaskEventType::TaskPaused => [NoteKind::User, 'Paused it.'],
            TaskEventType::TaskHarvested => [NoteKind::User, 'Harvested it.'],
            TaskEventType::TaskReplanted => [NoteKind::User, 'Replanted it.'],
            TaskEventType::TaskRenamed => [NoteKind::User, 'Renamed it “'.$this->text($payload, 'to').'”.'],
            TaskEventType::TaskInterpreted => [NoteKind::Done, 'Understood the request.'],
            TaskEventType::TaskPlanned => [NoteKind::Done, $this->planned($payload)],
            TaskEventType::QuestionAsked => [NoteKind::Info, 'Asked: '.$this->name($this->questions, $payload, 'question_id')],
            TaskEventType::QuestionAnswered => [NoteKind::User, 'Answered “'.$this->name($this->questions, $payload, 'question_id').'”.'],
            TaskEventType::QuestionSkipped => [NoteKind::User, 'Skipped “'.$this->name($this->questions, $payload, 'question_id').'”.'],
            TaskEventType::QuestionReopened => [NoteKind::User, 'Reopened “'.$this->name($this->questions, $payload, 'question_id').'”.'],
            TaskEventType::StepCompleted => [NoteKind::Done, 'Finished “'.$this->name($this->steps, $payload, 'step_id').'”.'],
            TaskEventType::ArtifactVersionCreated => $this->version($payload),
            TaskEventType::ReviewCompleted => [NoteKind::Done, 'Reviewed everything together.'],
            TaskEventType::RunRetryScheduled => [NoteKind::Warn, 'Something went wrong, so it will try again shortly.'],
            TaskEventType::RunInterrupted => [NoteKind::Warn, 'The work stopped halfway, so it will pick it up again.'],
            TaskEventType::BudgetDeferred => [NoteKind::Info, 'Resting until there is budget again.'],
            TaskEventType::TaskStatusChanged => $this->status($payload),
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private function planned(array $payload): string
    {
        $steps = is_array($payload['steps'] ?? null) ? count($payload['steps']) : 0;

        return $steps === 1 ? 'Mapped out 1 step.' : "Mapped out {$steps} steps.";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{NoteKind, string}|null
     */
    private function version(array $payload): ?array
    {
        $title = $this->name($this->artifacts, $payload, 'artifact_id');

        return match ($payload['source'] ?? null) {
            'user_edit' => [NoteKind::User, "Edited “{$title}”."],
            'restore' => [NoteKind::User, "Went back to an earlier version of “{$title}”."],
            'revise' => [NoteKind::Done, "Rewrote “{$title}”."],
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
            'ripe' => [NoteKind::Done, 'Ripe. Everything is written and reviewed.'],
            'waiting' => [NoteKind::Info, 'Did everything it could for now and is waiting for you.'],
            'stalled' => [NoteKind::Error, 'Stalled: '.$this->text($payload, 'reason')],
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

        return is_string($id) ? ($names[$id] ?? 'something that is gone') : 'something that is gone';
    }

    /** @param array<string, mixed> $payload */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
