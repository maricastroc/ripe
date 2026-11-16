<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestContext
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $task = $request->route('task');

        Context::add(array_filter([
            'request_id' => $id,
            'route' => $request->route()?->getName(),
            'task_id' => is_string($task) && Str::isUuid($task) ? $task : null,
        ]));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
