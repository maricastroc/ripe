<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Orchestration\AgentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property AgentStatus $resource */
final class AgentStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->resource;

        return [
            'hosting' => $status->hosting,
            'mode' => $status->mode,
            'message' => $status->message,
            'driver' => $status->driver,
            'model' => $status->model,
            'online' => $status->online,
            'models' => $status->models,
            'settings' => $status->settings === null ? null : [
                'pace' => $status->settings->pace,
                'paused' => $status->settings->paused,
                'model' => $status->settings->model,
            ],
            'current' => $status->current === null ? null : [
                'taskId' => $status->current->task_id,
                'taskTitle' => $status->current->task?->title,
                'kind' => $status->current->kind,
                'subject' => $status->current->step->title ?? $status->current->artifact?->title,
            ],
            'elsewhere' => $status->elsewhere,
            'queued' => $status->queued,
            'resumesAt' => $status->resumesAt?->toIso8601ZuluString('microsecond'),
            'rest' => $status->rest,
        ];
    }
}
