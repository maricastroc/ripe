<?php

declare(strict_types=1);

namespace App\Orchestration;

use App\Enums\LeaseRenewal;
use App\Exceptions\Inference\CallAborted;
use App\Inference\Contracts\CallSupervisor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

final class LeaseSupervisor implements CallSupervisor
{
    private CarbonImmutable $nextRenewal;

    private int $renewals = 0;

    public function __construct(
        private readonly Leases $leases,
        private readonly string $runId,
        private readonly string $token,
        private CarbonImmutable $confirmedUntil,
        private readonly float $ttl,
        private readonly float $renewInterval,
        private readonly float $safetyMargin,
    ) {
        $this->nextRenewal = CarbonImmutable::now()->addMilliseconds((int) round($renewInterval * 1000));
    }

    public function tick(): void
    {
        $now = CarbonImmutable::now();

        if ($now->lessThan($this->nextRenewal)) {
            $this->guardConfirmation($now);

            return;
        }

        try {
            $expiresAt = $now->addMilliseconds((int) round($this->ttl * 1000));
            $renewal = $this->leases->renew($this->runId, $this->token, $now, $expiresAt);
        } catch (QueryException) {
            $this->guardConfirmation($now);

            return;
        }

        match ($renewal) {
            LeaseRenewal::Renewed => $this->renewed($now, $expiresAt),
            LeaseRenewal::Cancelled => throw new CallAborted('cancelled', 'The run was cancelled while the model was answering.'),
            LeaseRenewal::Lost => throw new CallAborted('lease_lost', 'The lease expired or passed to another attempt.'),
        };
    }

    public function renewals(): int
    {
        return $this->renewals;
    }

    private function renewed(CarbonImmutable $now, CarbonImmutable $expiresAt): void
    {
        $this->confirmedUntil = $expiresAt;
        $this->nextRenewal = $now->addMilliseconds((int) round($this->renewInterval * 1000));
        $this->renewals++;
    }

    private function guardConfirmation(CarbonImmutable $now): void
    {
        if ($now->greaterThanOrEqualTo($this->confirmedUntil->subMilliseconds((int) round($this->safetyMargin * 1000)))) {
            throw new CallAborted('lease_unconfirmed', 'The lease could not be confirmed before it ran out.');
        }
    }
}
