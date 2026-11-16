<?php

declare(strict_types=1);

use App\Exceptions\AgentSettingsLocked;
use App\Exceptions\AgentSettingsRejected;
use App\Exceptions\TaskActionRejected;
use App\Exceptions\VersionConflict;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\ThroughGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignRequestContext::class, ThroughGateway::class, EnsureSameOrigin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->report(function (TaskActionRejected|VersionConflict|AgentSettingsLocked|AgentSettingsRejected $exception): false {
            Log::info('A request was refused.', ['reason' => class_basename($exception)]);

            return false;
        });

        $exceptions->report(function (QueryException $exception): false {
            $origin = collect($exception->getTrace())->first(static fn (array $frame): bool => str_starts_with($frame['file'] ?? '', app_path()));

            Log::error('A database query failed.', [
                'sqlstate' => $exception->errorInfo[0] ?? null,
                'sql' => $exception->getSql(),
                'connection' => $exception->getConnectionName(),
                'origin' => $origin === null ? null : str_replace(base_path().'/', '', $origin['file'] ?? '').':'.($origin['line'] ?? '?'),
            ]);

            return false;
        });

        $exceptions->render(fn (TaskActionRejected $exception): JsonResponse => new JsonResponse(['message' => $exception->getMessage()], 409));

        $exceptions->render(fn (VersionConflict $exception): JsonResponse => new JsonResponse([
            'message' => $exception->getMessage(),
            'currentVersionId' => $exception->currentVersionId,
            'draftRevision' => $exception->draftRevision,
        ], 409));

        $exceptions->render(fn (AgentSettingsLocked $exception): JsonResponse => new JsonResponse(['message' => $exception->getMessage()], 403));

        $exceptions->render(fn (AgentSettingsRejected $exception): JsonResponse => new JsonResponse([
            'message' => $exception->getMessage(),
            'errors' => ['model' => [$exception->getMessage()]],
        ], 422));

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') || $exception->getStatusCode() !== 404) {
                return null;
            }

            return new JsonResponse(['message' => 'Nothing grows here.'], 404, $exception->getHeaders());
        });
    })->create();
