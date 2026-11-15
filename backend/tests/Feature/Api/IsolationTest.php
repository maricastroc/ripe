<?php

declare(strict_types=1);

use App\Examples\ExampleGarden;
use App\Models\Artifact;
use App\Models\ArtifactDraft;
use App\Models\ArtifactVersion;
use App\Models\Question;
use App\Models\Task;

const RESOURCE_ROUTES = [
    'view a task' => ['GET', 'tasks/{task}', []],
    'rename a task' => ['PATCH', 'tasks/{task}', ['title' => 'Mine now']],
    'delete a task' => ['DELETE', 'tasks/{task}', []],
    'defer a task' => ['POST', 'tasks/{task}/defer', []],
    'resume a task' => ['POST', 'tasks/{task}/resume', []],
    'pause a task' => ['POST', 'tasks/{task}/pause', []],
    'harvest a task' => ['POST', 'tasks/{task}/harvest', []],
    'replant a task' => ['POST', 'tasks/{task}/replant', []],
    'refresh a task' => ['POST', 'tasks/{task}/refresh', []],
    'answer a question' => ['POST', 'questions/{question}/answer', ['answer' => 'Mine now.']],
    'skip a question' => ['POST', 'questions/{question}/skip', []],
    'reopen a question' => ['POST', 'questions/{question}/reopen', []],
    'save a draft' => ['PUT', 'artifacts/{artifact}/draft', ['content' => 'Mine now.', 'baseVersionId' => '{version}', 'draftRevision' => null]],
    'keep a draft' => ['POST', 'artifacts/{artifact}/draft/keep', ['baseVersionId' => '{version}', 'draftRevision' => 1]],
    'discard a draft' => ['DELETE', 'artifacts/{artifact}/draft', ['draftRevision' => 1]],
    'restore a version' => ['POST', 'artifacts/{artifact}/restore', ['baseVersionId' => '{version}']],
    'revise a piece' => ['POST', 'artifacts/{artifact}/revise', ['instruction' => 'Mine now.']],
];

/** @return array{string, array<string, mixed>} */
function routeFor(string $template, array $body, Task $task): array
{
    $question = Question::query()->where('task_id', $task->id)->orderBy('position')->first();
    $artifact = Artifact::query()->where('task_id', $task->id)->orderBy('created_at')->orderBy('id')->first();
    $ids = ['{task}' => $task->id, '{question}' => $question?->id, '{artifact}' => $artifact?->id, '{version}' => $artifact?->current_version_id];

    return [
        '/api/'.strtr($template, array_map(static fn (?string $id): string => (string) $id, $ids)),
        array_map(static fn (mixed $value): mixed => is_string($value) ? strtr($value, array_map(static fn (?string $id): string => (string) $id, $ids)) : $value, $body),
    ];
}

/** @return array{int, int, int, int} */
function footprint(Task $task): array
{
    return [(int) Task::query()->whereKey($task->id)->value('version'), Task::query()->count(), ArtifactVersion::query()->count(), ArtifactDraft::query()->count()];
}

it('hides every task from other visitors, whatever the request carries', function (string $method, string $template, array $body): void {
    fakeAgent([question('Who is it for?', 'optional')]);
    [, $owner] = visitor();
    [, $intruder] = visitor();
    $task = ripen(apiTask($owner));
    [$uri, $payload] = routeFor($template, $body, $task);
    $before = footprint($task);

    api($intruder)->json($method, $uri, $payload)->assertNotFound()->assertJsonPath('message', 'Nothing grows here.');
    api($intruder)->json($method, $uri, ['garbage' => true])->assertNotFound();
    api()->json($method, $uri, $payload)->assertNotFound();

    expect(footprint($task))->toBe($before);
    expectJournalConsistent($task);
})->with(RESOURCE_ROUTES);

it('never lists another visitor tasks in the garden', function (): void {
    fakeAgent();
    [, $owner] = visitor();
    [, $intruder] = visitor();
    apiTask($owner);

    api($intruder)->getJson('/api/garden')->assertOk()->assertJsonPath('tasks', []);
});

it('shows the example to everyone and lets nobody change it', function (string $method, string $template, array $body): void {
    $example = app(ExampleGarden::class)->ensure();
    [, $token] = visitor();
    [$uri, $payload] = routeFor($template, $body, $example);
    $before = footprint($example);

    if ($method === 'GET') {
        api($token)->getJson($uri)->assertOk()->assertJsonPath('task.isExample', true);
        api()->getJson($uri)->assertOk();

        return;
    }

    api($token)->json($method, $uri, $payload)->assertForbidden()->assertJsonPath('message', 'Examples can only be read.');

    expect(footprint($example))->toBe($before);
    expectJournalConsistent($example);
})->with(RESOURCE_ROUTES);

it('answers an unknown or malformed id like a missing one', function (string $uri): void {
    fakeAgent();
    [, $token] = visitor();

    api($token)->getJson($uri)->assertNotFound()->assertJsonPath('message', 'Nothing grows here.');
})->with([
    'an unknown task' => ['/api/tasks/01a1dfba-e489-7b89-a8fa-8d196860a3a7'],
    'a malformed task id' => ['/api/tasks/not-a-uuid'],
    'an injection attempt' => ["/api/tasks/1'%20or%20'1'='1"],
]);
