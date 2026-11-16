<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Orchestration\Execution\RunExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;

final class ExecuteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout;

    public function __construct(public readonly string $runId)
    {
        $this->onConnection(Config::string('ripe.agent.queue_connection'));
        $this->onQueue(Config::string('ripe.agent.queue'));
        $this->timeout = Config::integer('ripe.agent.worker_timeout');
    }

    public function handle(RunExecutor $executor): void
    {
        Context::forget(['task_id', 'attempt_id']);
        Context::add('run_id', $this->runId);
        $jobId = $this->job?->getJobId();

        $executor->execute($this->runId, $jobId === null ? null : (string) $jobId);
    }
}
