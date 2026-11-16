<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Visitors\PruneGardens;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ripe:prune')]
#[Description('Forget the tasks past their retention date and the visitors left without tasks')]
final class PruneGardensCommand extends Command
{
    public function handle(PruneGardens $prune): int
    {
        $pruned = $prune->handle(CarbonImmutable::now());

        $this->line("forgot {$pruned['tasks']} tasks and {$pruned['visitors']} visitors");

        return self::SUCCESS;
    }
}
