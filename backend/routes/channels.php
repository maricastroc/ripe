<?php

declare(strict_types=1);

use App\Broadcasting\GreenhouseChannel;
use App\Broadcasting\TaskChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('task.{task}', TaskChannel::class, ['guards' => ['visitor']]);
Broadcast::channel('greenhouse.{visitorId}', GreenhouseChannel::class, ['guards' => ['visitor']]);
