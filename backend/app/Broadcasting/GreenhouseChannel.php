<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Visitor;

final class GreenhouseChannel
{
    public function join(Visitor $visitor, string $visitorId): bool
    {
        return hash_equals($visitor->id, $visitorId);
    }
}
