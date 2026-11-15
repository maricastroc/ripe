<?php

declare(strict_types=1);

use App\Enums\ArtifactKind;
use App\Enums\ArtifactVersionSource;
use App\Enums\AttemptOutcome;
use App\Enums\Deliverable;
use App\Enums\EventActor;
use App\Enums\Fruit;
use App\Enums\OutputFormat;
use App\Enums\QuestionKind;
use App\Enums\QuestionStatus;
use App\Enums\RunDeferral;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

it('allows in the database exactly the values of the enum', function (string $constraint, string $enum): void {
    $definition = DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', [$constraint]);

    expect($definition)->toBeString();

    preg_match_all("/'([a-z_]+)'::character varying/", (string) $definition, $matches);

    $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    sort($values);
    $allowed = $matches[1];
    sort($allowed);

    expect($allowed)->toBe($values);
})->with([
    ['tasks_status_check', TaskStatus::class],
    ['tasks_deliverable_check', Deliverable::class],
    ['tasks_fruit_check', Fruit::class],
    ['steps_output_format_check', OutputFormat::class],
    ['steps_status_check', StepStatus::class],
    ['questions_kind_check', QuestionKind::class],
    ['questions_status_check', QuestionStatus::class],
    ['artifacts_kind_check', ArtifactKind::class],
    ['artifact_versions_source_check', ArtifactVersionSource::class],
    ['agent_runs_kind_check', RunKind::class],
    ['agent_runs_status_check', RunStatus::class],
    ['agent_runs_deferred_reason_check', RunDeferral::class],
    ['run_attempts_outcome_check', AttemptOutcome::class],
    ['task_events_actor_check', EventActor::class],
]);

it('accepts every event type the application can record', function (TaskEventType $type): void {
    expect(DB::scalar("select ? ~ '^[a-z]+(\\.[a-z_]+)+$'", [$type->value]))->toBeTrue();
})->with(TaskEventType::cases());
