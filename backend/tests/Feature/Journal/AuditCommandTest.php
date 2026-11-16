<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Examples\ExampleLibrary;
use App\Exceptions\Inference\ModelUnavailable;
use App\Models\Task;

it('audits every task, the recorded examples included, and fails when a task disagrees with its journal', function (): void {
    fakeAgent(first: [new ModelUnavailable('The model is down for a moment.')]);
    $task = ripen(plantTask());
    expect(app(ExampleLibrary::class)->install(ExampleLibrary::directory())['installed'])->toBe(2);

    $this->artisan('ripe:audit')->expectsOutputToContain('3 tasks checked, 0 with problems.')->assertSuccessful();
    $this->artisan("ripe:inspect {$task->id}")
        ->expectsOutputToContain('driver_unavailable')
        ->expectsOutputToContain('The journal agrees with the persisted state.')
        ->assertSuccessful();
    $this->artisan('ripe:inspect')->expectsOutputToContain('Attempts, last 24 hours')->assertSuccessful();

    Task::query()->whereKey($task->id)->update(['status' => TaskStatus::Waiting]);

    $this->artisan('ripe:audit')->expectsOutputToContain('the task is waiting but the journal says ripe')->assertFailed();
    $this->artisan("ripe:audit {$task->id}")->expectsOutputToContain('1 task checked, 1 with problems.')->assertFailed();
    $this->artisan('ripe:audit not-a-task')->assertFailed();
});
