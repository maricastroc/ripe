<?php

declare(strict_types=1);

use App\Agent\AgentRequests;
use App\Agent\Prompts\PromptBook;
use App\Agent\SafetyIdentifier;
use App\Enums\RunKind;
use Illuminate\Support\Facades\Config;

it('carries the schema of structured prompts', function (): void {
    $request = (new AgentRequests)->build(RunKind::Interpret, (new PromptBook)->interpret('Plan the trip.'), 'ripe_x');

    expect($request->schema?->name)->toBe('interpretation')
        ->and($request->safetyIdentifier)->toBe('ripe_x');
});

it('identifies visitors to OpenAI without revealing them', function (): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $visitor = '01a1df67-1811-7d68-b2f3-6a77caf912f9';
    $identifier = SafetyIdentifier::forVisitor($visitor);

    expect($identifier)->toStartWith('ripe_')
        ->and(strlen($identifier))->toBeLessThanOrEqual(128)
        ->and($identifier)->not->toContain($visitor)
        ->and(SafetyIdentifier::forVisitor($visitor))->toBe($identifier)
        ->and(SafetyIdentifier::forVisitor('another'))->not->toBe($identifier);
});
