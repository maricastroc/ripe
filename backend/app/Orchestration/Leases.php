<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\LeaseRenewal;
use App\Enums\RunStatus;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;

final class Leases
{
    public function renew(string $runId, string $token, CarbonImmutable $now, CarbonImmutable $expiresAt): LeaseRenewal
    {
        $renewed = AgentRun::query()
            ->whereKey($runId)
            ->where('status', RunStatus::Running)
            ->where('lease_token', $token)
            ->where('lease_expires_at', '>', $now)
            ->whereNull('cancel_requested_at')
            ->update(['lease_expires_at' => $expiresAt, 'last_heartbeat_at' => $now]);

        if ($renewed === 1) {
            return LeaseRenewal::Renewed;
        }

        $run = AgentRun::query()->find($runId);

        if ($run !== null && $run->status === RunStatus::Running && $run->lease_token === $token && $run->cancel_requested_at !== null) {
            return LeaseRenewal::Cancelled;
        }

        return LeaseRenewal::Lost;
    }
}
