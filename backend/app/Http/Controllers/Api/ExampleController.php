<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Examples\ExampleGarden;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ExampleController extends Controller
{
    use RespondsWithTasks;

    public function store(ExampleGarden $examples): JsonResponse
    {
        return $this->task($examples->ensure());
    }
}
