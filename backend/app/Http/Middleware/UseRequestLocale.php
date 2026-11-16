<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class UseRequestLocale
{
    public const array LOCALES = ['en', 'pt_BR'];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    public static function apply(Request $request): void
    {
        App::setLocale($request->getPreferredLanguage(self::LOCALES) ?? self::LOCALES[0]);
    }
}
