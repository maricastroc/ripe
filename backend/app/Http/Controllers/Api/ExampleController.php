<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskSummaryResource;
use App\Models\Task;
use App\Snapshots\TaskSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

final class ExampleController extends Controller
{
    public function index(TaskSnapshots $snapshots): JsonResponse
    {
        $examples = $snapshots->examples();
        $language = App::isLocale('pt_BR') ? 'pt' : 'en';
        $inLanguage = $examples->filter(static fn (Task $task): bool => str_starts_with(strtolower($task->language ?? 'en'), $language))->values();

        return new JsonResponse(['examples' => TaskSummaryResource::collection($inLanguage->isEmpty() ? $examples : $inLanguage)]);
    }
}
