<?php

declare(strict_types=1);

namespace App\Agent;

use Illuminate\Support\Facades\Config;

final class SafetyIdentifier
{
    public static function forVisitor(string $visitorId): string
    {
        return 'ripe_'.hash_hmac('sha256', $visitorId, Config::string('app.key'));
    }
}
