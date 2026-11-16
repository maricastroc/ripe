<?php

declare(strict_types=1);

namespace App\Http\Visitors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

final class VisitorCookie
{
    public function name(): string
    {
        return Config::string('ripe.visitor.cookie');
    }

    public function isSecure(): bool
    {
        return Config::boolean('ripe.visitor.secure');
    }

    public function issue(string $token): Cookie
    {
        $this->ensureCoherent();

        return Cookie::create(
            name: $this->name(),
            value: $token,
            expire: CarbonImmutable::now()->addDays(Config::integer('ripe.visitor.lifetime_days')),
            path: '/',
            domain: null,
            secure: $this->isSecure(),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function expire(): Cookie
    {
        return Cookie::create(
            name: $this->name(),
            value: '',
            expire: 1,
            path: '/',
            domain: null,
            secure: $this->isSecure(),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function ensureCoherent(): void
    {
        $name = $this->name();

        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new InvalidArgumentException("The visitor cookie name {$name} is not a valid cookie name.");
        }

        if (! $this->isSecure() && (str_starts_with($name, '__Host-') || str_starts_with($name, '__Secure-'))) {
            throw new InvalidArgumentException("The {$name} cookie must be Secure: browsers reject that prefix otherwise.");
        }

        if (! $this->isSecure() && app()->isProduction()) {
            throw new InvalidArgumentException('The visitor cookie must be Secure in production.');
        }
    }
}
