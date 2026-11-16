<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\Artifacts\DraftController;
use App\Http\Controllers\Api\Artifacts\KeepDraftController;
use App\Http\Controllers\Api\Artifacts\RestoreVersionController;
use App\Http\Controllers\Api\Artifacts\ReviseArtifactController;
use App\Http\Controllers\Api\AuthorizeChannelController;
use App\Http\Controllers\Api\ExampleController;
use App\Http\Controllers\Api\GardenController;
use App\Http\Controllers\Api\Questions\AnswerQuestionController;
use App\Http\Controllers\Api\Questions\ReopenQuestionController;
use App\Http\Controllers\Api\Questions\SkipQuestionController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\Tasks\DeferTaskController;
use App\Http\Controllers\Api\Tasks\HarvestTaskController;
use App\Http\Controllers\Api\Tasks\PauseTaskController;
use App\Http\Controllers\Api\Tasks\RefreshTaskController;
use App\Http\Controllers\Api\Tasks\ReplantTaskController;
use App\Http\Controllers\Api\Tasks\ResumeTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:reads')->group(static function (): void {
    Route::get('garden', [GardenController::class, 'show'])->name('garden.show');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->can('view', 'task')->name('tasks.show');
    Route::get('agent', [AgentController::class, 'show'])->name('agent.show');
    Route::get('examples', [ExampleController::class, 'index'])->name('examples.index');
    Route::post('broadcasting/auth', AuthorizeChannelController::class)->name('broadcasting.auth');
});

Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:plant')->name('tasks.store');

Route::middleware('throttle:mutations')->group(static function (): void {
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->can('update', 'task')->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->can('update', 'task')->name('tasks.destroy');
    Route::post('tasks/{task}/defer', DeferTaskController::class)->can('update', 'task')->name('tasks.defer');
    Route::post('tasks/{task}/resume', ResumeTaskController::class)->can('update', 'task')->name('tasks.resume');
    Route::post('tasks/{task}/pause', PauseTaskController::class)->can('update', 'task')->name('tasks.pause');
    Route::post('tasks/{task}/harvest', HarvestTaskController::class)->can('update', 'task')->name('tasks.harvest');
    Route::post('tasks/{task}/replant', ReplantTaskController::class)->can('update', 'task')->name('tasks.replant');
    Route::post('tasks/{task}/refresh', RefreshTaskController::class)->can('update', 'task')->name('tasks.refresh');

    Route::post('questions/{question}/answer', AnswerQuestionController::class)->can('update', 'question')->name('questions.answer');
    Route::post('questions/{question}/skip', SkipQuestionController::class)->can('update', 'question')->name('questions.skip');
    Route::post('questions/{question}/reopen', ReopenQuestionController::class)->can('update', 'question')->name('questions.reopen');

    Route::put('artifacts/{artifact}/draft', [DraftController::class, 'update'])->can('update', 'artifact')->name('artifacts.draft.save');
    Route::post('artifacts/{artifact}/draft/keep', KeepDraftController::class)->can('update', 'artifact')->name('artifacts.draft.keep');
    Route::delete('artifacts/{artifact}/draft', [DraftController::class, 'destroy'])->can('update', 'artifact')->name('artifacts.draft.discard');
    Route::post('artifacts/{artifact}/restore', RestoreVersionController::class)->can('update', 'artifact')->name('artifacts.restore');
    Route::post('artifacts/{artifact}/revise', ReviseArtifactController::class)->can('update', 'artifact')->name('artifacts.revise');

    Route::put('agent', [AgentController::class, 'update'])->name('agent.update');
});
