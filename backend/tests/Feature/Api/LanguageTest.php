<?php

declare(strict_types=1);

use App\Enums\EventActor;
use App\Enums\RunKind;
use App\Enums\TaskStatus;
use App\Observability\StallReason;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => fakeAgent());

it('answers in the language the site asks for and keeps English for everyone else', function (?int $attempts, string $english, string $portuguese): void {
    [, $token] = visitor();
    $task = apiTask($token);
    $stored = StallReason::compose(RunKind::Generate, $attempts, 'The model could not be reached.');
    DB::transaction(fn () => $task->refresh()->transitionTo(TaskStatus::Stalled, EventActor::Agent, $stored));

    $portugueseSite = api($token, ['Sec-Fetch-Site' => 'same-origin', 'Accept-Language' => 'pt-BR']);
    $portugueseSite->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('task.stallReason', $portuguese)
        ->assertJsonPath('task.events.0.message', "Travou: {$portuguese}");
    $portugueseSite->getJson('/api/tasks/01a1dfba-e489-7b89-a8fa-8d196860a3a7')->assertExactJson(['message' => 'Nada cresce aqui.']);

    api($token)->getJson("/api/tasks/{$task->id}")->assertJsonPath('task.stallReason', $english);

    expect($task->refresh()->stall_reason)->toBe($english);
})->with([
    'after the last attempt' => [3, 'Generate failed after 3 attempts. The model could not be reached.', 'Escrita falhou depois de 3 tentativas. Não foi possível alcançar o modelo.'],
    'at once' => [null, 'Generate: The model could not be reached.', 'Escrita: Não foi possível alcançar o modelo.'],
]);
