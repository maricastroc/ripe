<?php

declare(strict_types=1);

use App\Exceptions\AgentSettingsLocked;
use App\Exceptions\AgentSettingsRejected;
use App\Exceptions\TaskActionRejected;
use App\Exceptions\VersionConflict;
use App\Http\Middleware\EnsureSameOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [EnsureSameOrigin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

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
