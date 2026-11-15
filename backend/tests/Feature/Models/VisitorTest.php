<?php

declare(strict_types=1);

use App\Models\Visitor;

it('stores only a hash of the cookie token', function (): void {
    $token = bin2hex(random_bytes(32));
    $visitor = Visitor::factory()->create(['token_hash' => Visitor::hashToken($token)]);

    expect(Visitor::findByToken($token)?->is($visitor))->toBeTrue()
        ->and(Visitor::findByToken(bin2hex(random_bytes(32))))->toBeNull()
        ->and(json_encode($visitor->fresh()?->getAttributes()))->not->toContain($token)
        ->and($visitor->toArray())->not->toHaveKey('token_hash');
});
