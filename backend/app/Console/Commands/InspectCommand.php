<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Growth\GrowthCalculator;
use App\Inference\Contracts\QuotaLimited;
use App\Inference\InferenceManager;
use App\Journal\JournalAudit;
use App\Models\AgentRun;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Orchestration\AgentHalt;
use App\Orchestration\AgentStatusReader;
use App\Orchestration\Budget\InferenceBudget;
use App\Orchestration\Quota\QuotaGate;
use App\Retention\Retention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

#[Signature('ripe:inspect {task? : Show this task instead of the overview} {--events=15 : How many of the latest journal events to show}')]
#[Description('Show the agent, its quota and the last day of attempts, or one task with its runs, attempts and journal')]
final class InspectCommand extends Command
{
    public function handle(): int
    {
        $id = $this->argument('task');

        if (! is_string($id)) {
            $this->overview(CarbonImmutable::now());

            return self::SUCCESS;
        }

        $task = Str::isUuid($id) ? Task::query()->find($id) : null;

        if ($task === null) {
            $this->error("No task {$id}.");

            return self::FAILURE;
        }

        return $this->task($task);
    }

    private function overview(CarbonImmutable $now): void
    {
        $status = app(AgentStatusReader::class)->for(null);
        $halt = app(AgentHalt::class)->reason();

        $this->components->twoColumnDetail('<fg=gray>Agent</>', "{$status->driver} · {$status->model}");
        $this->components->twoColumnDetail('Mode', $status->mode->value.($status->rest === null ? '' : " ({$status->rest->value})").($status->resumesAt === null ? '' : ', until '.$status->resumesAt->toIso8601ZuluString()));

        if ($halt !== null) {
            $this->components->twoColumnDetail('Halted because', Str::limit($halt, 160));
        }

        $this->quota($now);
        $this->budget($now);

        $tasks = Task::query()->where('is_example', false)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Tasks</>', $this->counts($tasks->all()));
        $this->components->twoColumnDetail('Runs queued, due now', (string) AgentRun::query()->where('status', RunStatus::Queued)->where('available_at', '<=', $now)->count());
        $this->components->twoColumnDetail('Runs queued, later', (string) AgentRun::query()->where('status', RunStatus::Queued)->where('available_at', '>', $now)->count());
        $this->components->twoColumnDetail('Runs running', (string) AgentRun::query()->where('status', RunStatus::Running)->count());

        foreach (Task::query()->where('status', TaskStatus::Stalled)->latest('updated_at')->limit(5)->get(['id', 'title', 'stall_reason']) as $stalled) {
            $this->components->twoColumnDetail("Stalled {$stalled->id}", Str::limit((string) $stalled->stall_reason, 100));
        }

        $since = $now->subDay();
        $outcomes = RunAttempt::query()->where('started_at', '>', $since)->whereNotNull('finished_at')->selectRaw('outcome, count(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Attempts, last 24 hours</>', $this->counts($outcomes->all()));

        $rows = DB::select(
            "select r.kind,
                count(*) as attempts,
                count(*) filter (where a.outcome = 'succeeded') as succeeded,
                percentile_cont(0.5) within group (order by a.ttft_ms) as ttft_p50,
                percentile_cont(0.95) within group (order by a.ttft_ms) as ttft_p95,
                percentile_cont(0.5) within group (order by a.duration_ms) as duration_p50,
                percentile_cont(0.95) within group (order by a.duration_ms) as duration_p95,
                coalesce(sum(a.prompt_tokens), 0) as prompt_tokens,
                coalesce(sum(a.completion_tokens), 0) as completion_tokens
             from run_attempts a join agent_runs r on r.id = a.run_id
             where a.started_at > ? and a.finished_at is not null
             group by r.kind order by r.kind",
            [$since],
        );

        $table = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $table[] = [
                    $row->kind,
                    $row->attempts,
                    $row->succeeded,
                    $this->ms($row->ttft_p50).' / '.$this->ms($row->ttft_p95),
                    $this->ms($row->duration_p50).' / '.$this->ms($row->duration_p95),
                    $this->number($row->prompt_tokens).' / '.$this->number($row->completion_tokens),
                ];
            }
        }

        if ($table !== []) {
            $this->table(['kind', 'attempts', 'saved', 'first token p50 / p95', 'duration p50 / p95', 'tokens in / out'], $table);
        }
    }

    private function quota(CarbonImmutable $now): void
    {
        try {
            $driver = app(InferenceManager::class)->current();

            if (! $driver instanceof QuotaLimited) {
                return;
            }

            $scope = $driver->quotaScope();
            $limits = $driver->quotaLimits();
        } catch (Throwable $unavailable) {
            $this->components->twoColumnDetail('Quota', 'unknown: '.class_basename($unavailable));

            return;
        }

        $usage = app(QuotaGate::class)->usage($scope, $now);
        $this->newLine();
        $this->components->twoColumnDetail("<fg=gray>Quota {$scope}</>", $usage->blockedUntil !== null && $usage->blockedUntil->greaterThan($now) ? 'blocked until '.$usage->blockedUntil->toIso8601ZuluString() : 'open');
        $this->components->twoColumnDetail('Requests, last minute / 24 hours', "{$usage->minuteRequests} of {$limits->requestsPerMinute} / {$usage->dayRequests} of {$limits->requestsPerDay}");
        $this->components->twoColumnDetail('Tokens, last minute / 24 hours', "{$usage->minuteTokens} of {$limits->tokensPerMinute} / {$usage->dayTokens} of {$limits->tokensPerDay}");
        $this->components->twoColumnDetail('Calls in flight', "{$usage->inFlight} of {$limits->concurrency}");
    }

    private function budget(CarbonImmutable $now): void
    {
        try {
            $metered = app(InferenceManager::class)->current()->isMetered();
        } catch (Throwable) {
            return;
        }

        if (! $metered) {
            return;
        }

        $standing = app(InferenceBudget::class)->standing(null, true, $now);
        $this->components->twoColumnDetail('Money budget', $standing->resting ? 'spent for today' : 'available');
    }

    private function task(Task $task): int
    {
        $now = CarbonImmutable::now();
        $task->load('visitor:id,last_seen_at');
        $growth = app(GrowthCalculator::class)->forTask($task);
        $expires = Retention::expiresAt($task);

        $this->components->twoColumnDetail('<fg=gray>Task</>', $task->id);
        $this->components->twoColumnDetail('Title', Str::limit((string) $task->title, 80));
        $this->components->twoColumnDetail('Status', $task->status->value.($task->is_example ? ', example' : '').($task->stall_reason === null ? '' : ': '.Str::limit($task->stall_reason, 120)));
        $this->components->twoColumnDetail('Stage', "{$growth->stage->value}, ".round($growth->maturity * 100).'% ripe');
        $this->components->twoColumnDetail('Version', (string) $task->version);
        $this->components->twoColumnDetail('Planted', (string) $task->created_at?->toIso8601ZuluString());
        $this->components->twoColumnDetail('Forgotten on', $expires?->toIso8601ZuluString() ?? 'never');

        $steps = $task->steps()->orderBy('position')->get(['position', 'title', 'status']);

        if ($steps->isNotEmpty()) {
            $this->table(['#', 'step', 'status'], $steps->map(static fn ($step): array => [$step->position + 1, Str::limit($step->title, 60), $step->status->value])->all());
        }

        $runs = AgentRun::query()->where('task_id', $task->id)->with(['attempts' => static fn ($query) => $query->orderBy('number'), 'step:id,title', 'artifact:id,title'])->orderBy('created_at')->orderBy('id')->get();
        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [$run->created_at?->format('m-d H:i:s'), $run->kind->value, Str::limit($run->step->title ?? $run->artifact->title ?? '', 30), $run->status->value.($run->deferred_reason === null ? '' : " ({$run->deferred_reason->value})"), '', '', '', ''];

            foreach ($run->attempts as $attempt) {
                $rows[] = [
                    $attempt->started_at?->format('m-d H:i:s'),
                    "  #{$attempt->number}",
                    $attempt->model ?? '',
                    $attempt->outcome->value ?? 'running',
                    $this->ms($attempt->duration_ms),
                    $this->ms($attempt->ttft_ms),
                    ($attempt->prompt_tokens ?? '–').' / '.($attempt->completion_tokens ?? '–'),
                    $attempt->error_class === null ? '' : Str::limit("{$attempt->error_class}: {$attempt->error_message}", 70),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['when', 'run', 'subject or model', 'status', 'duration', 'first token', 'tokens', 'error'], $rows);
        }

        $events = TaskEvent::query()->where('task_id', $task->id)->orderByDesc('sequence')->limit(max(0, (int) $this->option('events')))->get()->reverse();

        if ($events->isNotEmpty()) {
            $this->table(['#', 'when', 'event', 'by'], $events->map(static fn (TaskEvent $event): array => [$event->sequence, $event->occurred_at?->format('m-d H:i:s'), $event->type->value, $event->actor->value])->all());
        }

        $problems = app(JournalAudit::class)->problems($task);

        if ($problems === []) {
            $this->components->info('The journal agrees with the persisted state.');

            return self::SUCCESS;
        }

        $this->components->error('The journal disagrees with the persisted state:');
        $this->components->bulletList($problems);

        return self::FAILURE;
    }

    /** @param array<array-key, mixed> $counts */
    private function counts(array $counts): string
    {
        $parts = [];

        foreach ($counts as $label => $total) {
            $parts[] = "{$label} ".$this->number($total);
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }

    private function number(mixed $value): string
    {
        return is_numeric($value) ? (string) (int) $value : '–';
    }

    private function ms(mixed $milliseconds): string
    {
        if (! is_numeric($milliseconds)) {
            return '–';
        }

        $value = (float) $milliseconds;

        return $value >= 1000 ? number_format($value / 1000, 1).' s' : (int) round($value).' ms';
    }
}
