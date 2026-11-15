<?php

declare(strict_types=1);

namespace App\Http\Visitors;

use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

final readonly class VisitorResolver
{
    public const int TOKEN_BYTES = 32;

    public function __construct(private VisitorCookie $cookie) {}

    public function fromRequest(Request $request): ?Visitor
    {
        $token = $request->cookie($this->cookie->name());

        if (! is_string($token) || preg_match('/^[a-f0-9]{'.(self::TOKEN_BYTES * 2).'}$/', $token) !== 1) {
            return null;
        }

        $visitor = Visitor::findByToken($token);

        if ($visitor !== null) {
            $this->touch($visitor);
        }

        return $visitor;
    }

    /** @return array{Visitor, string} */
    public function create(): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $visitor = Visitor::query()->create(['token_hash' => Visitor::hashToken($token), 'last_seen_at' => CarbonImmutable::now()]);

        return [$visitor, $token];
    }

    private function touch(Visitor $visitor): void
    {
        $now = CarbonImmutable::now();
        $threshold = $now->subMinutes(Config::integer('ripe.visitor.touch_after_minutes'));

        if ($visitor->last_seen_at !== null && $visitor->last_seen_at->greaterThan($threshold)) {
            return;
        }

        Visitor::query()->whereKey($visitor->id)->where('last_seen_at', '<=', $threshold)->update(['last_seen_at' => $now]);
        $visitor->last_seen_at = $now;
    }
}
