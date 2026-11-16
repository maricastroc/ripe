<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ThroughGateway;
use App\Http\Visitors\VisitorResolver;
use App\Models\Visitor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Auth::viaRequest('visitor-cookie', fn (Request $request): ?Visitor => $this->app->make(VisitorResolver::class)->fromRequest($request));

        Route::pattern('task', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        Route::pattern('question', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        Route::pattern('artifact', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

        RateLimiter::for('plant', static fn (Request $request): array => [
            Limit::perHour(Config::integer('ripe.limits.plant_per_hour_per_visitor'))->by('plant:'.self::requester($request)),
            Limit::perHour(Config::integer('ripe.limits.plant_per_hour_per_ip'))->by('plant-ip:'.self::network($request)),
            Limit::perDay(Config::integer('ripe.limits.plant_per_day_per_visitor'))->by('plant-day:'.self::requester($request))->after(self::planted(...))->response(self::tomorrow(...)),
            Limit::perDay(Config::integer('ripe.limits.plant_per_day_per_network'))->by('plant-day-ip:'.self::network($request))->after(self::planted(...))->response(self::tomorrow(...)),
            Limit::perDay(Config::integer('ripe.limits.plant_per_day_global'))->by('plant-day-global')->after(self::planted(...))->response(self::tomorrow(...)),
        ]);

        RateLimiter::for('revisions', static fn (Request $request): array => [
            Limit::perDay(Config::integer('ripe.limits.revisions_per_day_per_visitor'))->by('revise-day:'.self::requester($request))->response(self::tomorrow(...)),
            Limit::perDay(Config::integer('ripe.limits.revisions_per_day_per_network'))->by('revise-day-ip:'.self::network($request))->response(self::tomorrow(...)),
        ]);

        RateLimiter::for('mutations', static fn (Request $request): Limit => Limit::perMinute(Config::integer('ripe.limits.mutations_per_minute'))->by('mutations:'.self::requester($request)));

        RateLimiter::for('reads', static fn (Request $request): Limit => Limit::perMinute(Config::integer('ripe.limits.reads_per_minute'))->by('reads:'.self::requester($request)));
    }

    private static function planted(Response $response): bool
    {
        return $response->getStatusCode() === 201;
    }

    /** @param array<string, string> $headers */
    private static function tomorrow(Request $request, array $headers): JsonResponse
    {
        return new JsonResponse(['message' => __('The greenhouse has done enough for today. Come back tomorrow.')], 429, $headers);
    }

    private static function requester(Request $request): string
    {
        $visitor = $request->user();

        return $visitor instanceof Visitor ? "visitor:{$visitor->id}" : 'ip:'.self::network($request);
    }

    private static function network(Request $request): string
    {
        $address = $request->attributes->get(ThroughGateway::CLIENT_ATTRIBUTE);

        return hash_hmac('sha256', is_string($address) ? $address : (string) $request->ip(), Config::string('app.key'));
    }
}
