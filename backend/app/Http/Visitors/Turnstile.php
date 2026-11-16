<?php

declare(strict_types=1);

namespace App\Http\Visitors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

final class Turnstile
{
    public function enabled(): bool
    {
        $secret = Config::get('ripe.turnstile.secret');

        return is_string($secret) && $secret !== '';
    }

    public function passes(string $token, ?string $address): bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post(Config::string('ripe.turnstile.verify_url'), array_filter([
                'secret' => Config::string('ripe.turnstile.secret'),
                'response' => $token,
                'remoteip' => $address,
            ]));
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
