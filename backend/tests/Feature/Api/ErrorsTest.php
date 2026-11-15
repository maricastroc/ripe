<?php

declare(strict_types=1);

use App\Models\Task;

beforeEach(fn () => fakeAgent());

it('explains invalid input field by field', function (): void {
    api()->postJson('/api/tasks', ['prompt' => 'hi'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.prompt.0', 'Write a little more about the task.');

    api()->postJson('/api/tasks', ['prompt' => str_repeat('a', 2001)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.prompt.0', 'Keep the task under 2000 characters.');

    expect(Task::query()->count())->toBe(0);
});

it('answers a change the task cannot take now with a conflict', function (): void {
    [, $token] = visitor();
    $task = ripen(apiTask($token));

    api($token)->postJson("/api/tasks/{$task->id}/defer")
        ->assertConflict()
        ->assertExactJson(['message' => 'This task is already ripe.']);
});

it('keeps internals out of error responses in production', function (): void {
    config(['app.debug' => false]);

    api()->getJson('/api/tasks/01a1dfba-e489-7b89-a8fa-8d196860a3a7')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Nothing grows here.']);

    api(headers: ['Sec-Fetch-Site' => 'cross-site'])->postJson('/api/tasks', ['prompt' => 'Write the note.'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Changes to Ripe must come from Ripe itself.']);
});

it('refuses to let the interface point the model at another address', function (): void {
    config(['ripe.mode' => 'local']);

    api()->putJson('/api/agent', ['baseUrl' => 'http://169.254.169.254/latest/meta-data'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.baseUrl.0', "The model's address is set by whoever runs Ripe, not from the interface.");
});
