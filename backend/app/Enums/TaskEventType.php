<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskEventType: string
{
    case TaskPlanted = 'task.planted';
    case TaskDeferred = 'task.deferred';
    case TaskPaused = 'task.paused';
    case TaskResumed = 'task.resumed';
    case TaskHarvested = 'task.harvested';
    case TaskReplanted = 'task.replanted';
    case TaskRenamed = 'task.renamed';
    case TaskStatusChanged = 'task.status_changed';
    case TaskInterpreted = 'task.interpreted';
    case TaskPlanned = 'task.planned';
    case QuestionAsked = 'question.asked';
    case QuestionAnswered = 'question.answered';
    case QuestionSkipped = 'question.skipped';
    case QuestionReopened = 'question.reopened';
    case StepReady = 'step.ready';
    case StepBlocked = 'step.blocked';
    case StepCompleted = 'step.completed';
    case ArtifactVersionCreated = 'artifact.version_created';
    case ReviewCompleted = 'review.completed';
    case RunScheduled = 'run.scheduled';
    case RunStarted = 'run.started';
    case RunRetryScheduled = 'run.retry_scheduled';
    case RunInterrupted = 'run.interrupted';
    case RunSucceeded = 'run.succeeded';
    case RunFailed = 'run.failed';
    case RunCancelled = 'run.cancelled';
    case RunResultRejected = 'run.result_rejected';
    case PlantStageChanged = 'plant.stage_changed';
    case BudgetDeferred = 'budget.deferred';
    case QuotaDeferred = 'quota.deferred';
}
