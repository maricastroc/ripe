<?php

declare(strict_types=1);

use App\Http\Visitors\VisitorCookie;
use App\Models\Task;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(fn () => fakeAgent());

function issuedCookie(TestResponse $response): ?Cookie
{
    return $response->getCookie(config('ripe.visitor.cookie'), decrypt: false);
}

it('issues a secure, http-only, same-site cookie with the first seed and keeps only its hash', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $response = api()->postJson('/api/tasks', ['prompt' => 'Write the thank-you note.'])->assertCreated();
    $cookie = issuedCookie($response);
    $visitor = Visitor::query()->sole();

    expect($cookie)->not->toBeNull()
        ->and($cookie?->getName())->toBe('__Host-ripe_visitor')
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($cookie?->getPath())->toBe('/')
        ->and($cookie?->getDomain())->toBeNull()
        ->and($cookie?->getExpiresTime())->toBe(CarbonImmutable::parse('2027-12-20 12:00:00')->getTimestamp())
        ->and($cookie?->getValue())->toMatch('/^[a-f0-9]{64}$/')
        ->and($visitor->token_hash)->toBe(hash('sha256', (string) $cookie?->getValue()))
        ->and($visitor->token_hash)->not->toBe($cookie?->getValue())
        ->and(Task::query()->sole()->visitor_id)->toBe($visitor->id);
});

it('keeps the same visitor for later seeds and issues no new cookie', function (): void {
    [$visitor, $token] = visitor();

    $response = api($token)->postJson('/api/tasks', ['prompt' => 'Write the thank-you note.'])->assertCreated();

    expect(issuedCookie($response))->toBeNull()
        ->and(Visitor::query()->count())->toBe(1)
        ->and(Task::query()->sole()->visitor_id)->toBe($visitor->id);
});

it('shows an empty garden to someone without a cookie and creates nobody', function (string $token): void {
    $test = $token === '' ? api() : api($token);

    $test->getJson('/api/garden')->assertOk()->assertJsonPath('tasks', []);

    expect(Visitor::query()->count())->toBe(0);
})->with([
    'no cookie' => [''],
    'an unknown token' => [str_repeat('a', 64)],
    'a malformed token' => ["' or 1=1 --"],
]);

it('remembers when the visitor was last seen, at most once an hour', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$visitor, $token] = visitor();

    Date::setTestNow(CarbonImmutable::parse('2026-11-15 12:30:00'));
    api($token)->getJson('/api/garden')->assertOk();

    expect($visitor->refresh()->last_seen_at?->toDateTimeString())->toBe('2026-11-15 12:00:00');

    Date::setTestNow(CarbonImmutable::parse('2026-11-15 13:05:00'));
    api($token)->getJson('/api/garden')->assertOk();

    expect($visitor->refresh()->last_seen_at?->toDateTimeString())->toBe('2026-11-15 13:05:00');
});

it('refuses cookie settings that browsers would drop or that leak over plain http', function (string $name, bool $secure, bool $production, string $message): void {
    config(['ripe.visitor.cookie' => $name, 'ripe.visitor.secure' => $secure]);
    app()->detectEnvironment(static fn (): string => $production ? 'production' : 'local');

    expect(fn () => app(VisitorCookie::class)->issue(str_repeat('a', 64)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a host prefix without Secure' => ['__Host-ripe_visitor', false, false, 'must be Secure'],
    'a secure prefix without Secure' => ['__Secure-ripe_visitor', false, false, 'must be Secure'],
    'plain http in production' => ['ripe_visitor', false, true, 'Secure in production'],
    'an invalid name' => ['ripe visitor', true, false, 'not a valid cookie name'],
]);

it('allows a plain cookie over http for local development', function (): void {
    config(['ripe.visitor.cookie' => 'ripe_visitor', 'ripe.visitor.secure' => false]);

    $cookie = app(VisitorCookie::class)->issue(str_repeat('a', 64));

    expect($cookie->isSecure())->toBeFalse()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX);
});
