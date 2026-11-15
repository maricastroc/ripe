<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthorizeChannelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AuthorizeChannelController extends Controller
{
    public function __invoke(AuthorizeChannelRequest $request): JsonResponse
    {
        $signed = Broadcast::auth($request);

        if (! is_array($signed) || ! is_string($signed['auth'] ?? null)) {
            throw new AccessDeniedHttpException;
        }

        return new JsonResponse(['auth' => $signed['auth']]);
    }
}
