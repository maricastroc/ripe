<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('publishes an OpenAPI document that matches the code', function (): void {
    $exported = storage_path('framework/testing/openapi.json');
    @mkdir(dirname($exported), 0755, true);

    expect(Artisan::call('scramble:export', ['--path' => $exported]))->toBe(0)
        ->and(file_get_contents($exported))->toBe(file_get_contents(base_path('../docs/api/openapi.json')));

    unlink($exported);
});

it('documents every API route', function (): void {
    $document = json_decode((string) file_get_contents(base_path('../docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $documented = collect($document['paths'])->flatMap(static fn (array $operations, string $path): array => array_map(static fn (string $method): string => strtoupper($method).' /api'.$path, array_keys($operations)))->sort()->values()->all();
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(static fn ($route): array => array_map(static fn (string $method): string => "{$method} /{$route->uri()}", array_diff($route->methods(), ['HEAD'])))
        ->sort()->values()->all();

    expect($documented)->toBe($routes)->toHaveCount(23);
});
