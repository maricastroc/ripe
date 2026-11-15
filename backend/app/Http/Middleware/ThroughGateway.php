<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ThroughGateway
{
    public const string SECRET_HEADER = 'X-Ripe-Gateway';

    public const string CLIENT_HEADER = 'X-Ripe-Client-Ip';

    public const string CLIENT_ATTRIBUTE = 'ripe.client_ip';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $trusted = $this->presentsSecret($request);

        if (! $trusted && Config::boolean('ripe.gateway.required')) {
            throw new AccessDeniedHttpException('Ripe answers through its own site.');
        }

        $reported = $request->headers->get(self::CLIENT_HEADER);
        $request->attributes->set(
            self::CLIENT_ATTRIBUTE,
            $trusted && is_string($reported) && filter_var($reported, FILTER_VALIDATE_IP) !== false ? $reported : $request->ip(),
        );

        return $next($request);
    }

    private function presentsSecret(Request $request): bool
    {
        $secret = Config::get('ripe.gateway.secret');
        $presented = $request->headers->get(self::SECRET_HEADER);

        return is_string($secret) && $secret !== '' && is_string($presented) && hash_equals($secret, $presented);
    }
}
