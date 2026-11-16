<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Orchestration\Sweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ripe:reconcile')]
#[Description('Recover expired leases, re-dispatch lost runs and reconcile idle tasks')]
final class ReconcileAgentCommand extends Command
{
    public function handle(Sweeper $sweeper): int
    {
        $report = $sweeper->sweep();

        $this->line(sprintf(
            'expired %d, redispatched %d, reconciled %d, orphaned reservations settled %d%s',
            $report['expired'],
            $report['redispatched'],
            $report['reconciled'],
            $report['orphans_settled'],
            $report['halt_lifted'] ? ', agent resumed' : '',
        ));

        return self::SUCCESS;
    }
}
