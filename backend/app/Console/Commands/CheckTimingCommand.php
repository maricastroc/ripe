<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Orchestration\TimingConstraints;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ripe:timing')]
#[Description('Check that leases, model calls, the worker timeout and queue redelivery are ordered safely')]
final class CheckTimingCommand extends Command
{
    public function handle(TimingConstraints $constraints): int
    {
        $violations = $constraints->violations();

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        if ($violations !== []) {
            return self::FAILURE;
        }

        $this->info('Leases, model calls, the worker timeout and queue redelivery are ordered safely.');

        return self::SUCCESS;
    }
}
