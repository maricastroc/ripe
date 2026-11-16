<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class EnsureSameOrigin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $this->isSameOrigin($request)) {
            throw new AccessDeniedHttpException(__('Changes to Ripe must come from Ripe itself.'));
        }

        return $next($request);
    }

    private function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');

        return match ($site) {
            'same-origin' => $origin === null || $this->isAllowed($origin) || $this->isOwnOrigin($request, $origin),
            'same-site' => $origin !== null && $this->isAllowed($origin),
            null => $origin !== null && ($this->isAllowed($origin) || $this->isOwnOrigin($request, $origin)),
            default => false,
        };
    }

    private function isAllowed(string $origin): bool
    {
        $allowed = array_map(
            static fn (string $entry): string => rtrim($entry, '/'),
            array_filter(Config::array('ripe.http.allowed_origins'), is_string(...)),
        );

        return in_array(rtrim($origin, '/'), $allowed, true);
    }

    private function isOwnOrigin(Request $request, string $origin): bool
    {
        return $origin === $request->getSchemeAndHttpHost();
    }
}
