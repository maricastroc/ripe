<?php

declare(strict_types=1);

namespace App\Orchestration\Budget;

use App\Enums\BudgetScope;
use App\Enums\ChargeBasis;
use App\Enums\RunDeferral;
use App\Enums\RunStatus;
use App\Exceptions\BudgetExhausted;
use App\Models\AgentRun;
use App\Models\InferenceBudgetDay;
use App\Models\RunAttempt;
use App\Models\Task;
use App\Models\Visitor;
use App\Orchestration\AttemptReport;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class InferenceBudget
{
    public function reserve(Task $task, int $amount, CarbonImmutable $now): Reservation|Shortfall
    {
        Transactions::require('Reserving inference budget');

        $day = self::dayOf($now);
        $visitorId = $task->is_example ? null : $task->visitor_id;
        $this->openDay($day, $now);

        try {
            return DB::transaction(function () use ($day, $amount, $visitorId, $now): Reservation {
                $global = DB::update(
                    'update inference_budget_days set reserved_microusd = reserved_microusd + ?, updated_at = ?
                     where day = ? and spent_microusd + estimated_microusd + reserved_microusd + ? <= limit_microusd',
                    [$amount, $now, $day, $amount],
                );

                if ($global === 0) {
                    throw new BudgetExhausted(BudgetScope::Global);
                }

                if ($visitorId !== null) {
                    DB::insert(
                        'insert into visitor_budget_days (visitor_id, day, created_at, updated_at) values (?, ?, ?, ?) on conflict (visitor_id, day) do nothing',
                        [$visitorId, $day, $now, $now],
                    );

                    $share = DB::update(
                        'update visitor_budget_days set reserved_microusd = reserved_microusd + ?, updated_at = ?
                         where visitor_id = ? and day = ?
                         and spent_microusd + estimated_microusd + reserved_microusd + ? <= (
                             select floor(limit_microusd * cast(? as numeric)) from inference_budget_days where day = ?
                         )',
                        [$amount, $now, $visitorId, $day, $amount, self::visitorShare(), $day],
                    );

                    if ($share === 0) {
                        throw new BudgetExhausted(BudgetScope::Visitor);
                    }
                }

                return new Reservation($day, $amount, $visitorId);
            });
        } catch (BudgetExhausted $exhausted) {
            return new Shortfall($exhausted->scope, $this->retryAt($now));
        }
    }

    public function settle(RunAttempt $attempt, AttemptReport $report, CarbonImmutable $now): void
    {
        if ($attempt->reserved_microusd <= 0 || $attempt->settled_at !== null || $attempt->budget_day === null) {
            return;
        }

        Transactions::require('Settling inference budget');

        [$charged, $basis] = self::charge($attempt->reserved_microusd, $report);

        $claimed = DB::update(
            'update run_attempts set settled_at = ?, charged_microusd = ?, charge_basis = ? where id = ? and settled_at is null',
            [$now, $charged, $basis->value, $attempt->id],
        );

        if ($claimed === 0) {
            return;
        }

        $column = $basis->confirmed() ? 'spent_microusd' : 'estimated_microusd';
        $released = $attempt->reserved_microusd;

        DB::update(
            "update inference_budget_days set reserved_microusd = reserved_microusd - ?, {$column} = {$column} + ?, updated_at = ? where day = ?",
            [$released, $charged, $now, $attempt->budget_day],
        );

        if ($attempt->budget_visitor_id !== null) {
            DB::update(
                "update visitor_budget_days set reserved_microusd = reserved_microusd - ?, {$column} = {$column} + ?, updated_at = ? where visitor_id = ? and day = ?",
                [$released, $charged, $now, $attempt->budget_visitor_id, $attempt->budget_day],
            );
        }

        $attempt->forceFill(['settled_at' => $now, 'charged_microusd' => $charged, 'charge_basis' => $basis])
            ->syncOriginalAttributes(['settled_at', 'charged_microusd', 'charge_basis']);
    }

    public function settleOrphans(CarbonImmutable $now): int
    {
        $settled = 0;

        $orphans = RunAttempt::query()->where('reserved_microusd', '>', 0)->whereNull('settled_at')->whereNotNull('finished_at')->pluck('id');

        foreach ($orphans as $id) {
            DB::transaction(function () use ($id, $now, &$settled): void {
                $attempt = RunAttempt::query()->whereKey($id)->whereNull('settled_at')->lockForUpdate()->first();

                if ($attempt !== null) {
                    $this->settle($attempt, AttemptReport::withDetail('orphaned'), $now);
                    $settled++;
                }
            });
        }

        return $settled;
    }

    public function settleOpenReservations(Task $task, CarbonImmutable $now): void
    {
        $open = RunAttempt::query()
            ->whereIn('run_id', AgentRun::query()->where('task_id', $task->id)->select('id'))
            ->where('reserved_microusd', '>', 0)
            ->whereNull('settled_at')
            ->lockForUpdate()
            ->get();

        foreach ($open as $attempt) {
            $this->settle($attempt, AttemptReport::withDetail('forgotten'), $now);
        }
    }

    public function standing(?Visitor $visitor, bool $metered, CarbonImmutable $now): BudgetStanding
    {
        if (! $metered) {
            return new BudgetStanding(false, null);
        }

        $tomorrow = self::nextDay($now);
        $day = InferenceBudgetDay::query()->find(self::dayOf($now));
        $limit = $day === null ? self::dailyLimit() : $day->limit_microusd;
        $used = $day === null ? 0 : $day->spent_microusd + $day->estimated_microusd + $day->reserved_microusd;

        if ($limit - $used < Config::integer('ripe.budget.resting_below_microusd')) {
            return new BudgetStanding(true, $tomorrow);
        }

        if ($visitor === null) {
            return new BudgetStanding(false, null);
        }

        $waiting = AgentRun::query()
            ->where('status', RunStatus::Queued)
            ->where('deferred_reason', RunDeferral::Budget)
            ->whereHas('task', static fn ($query) => $query->where('visitor_id', $visitor->id))
            ->exists();

        return new BudgetStanding($waiting, $waiting ? $tomorrow : null);
    }

    public static function dayOf(CarbonImmutable $now): string
    {
        return $now->utc()->toDateString();
    }

    public static function dailyLimit(): int
    {
        return (int) round(Config::float('ripe.budget.daily_usd') * 1_000_000);
    }

    private static function visitorShare(): float
    {
        return Config::float('ripe.budget.visitor_share');
    }

    private static function nextDay(CarbonImmutable $now): CarbonImmutable
    {
        return $now->utc()->startOfDay()->addDay();
    }

    /** @return array{int, ChargeBasis} */
    private static function charge(int $reserved, AttemptReport $report): array
    {
        return match ($report->billing()) {
            ChargeBasis::Reported => [(int) $report->costMicrodollars, ChargeBasis::Reported],
            ChargeBasis::Estimated => [(int) $report->costMicrodollars, ChargeBasis::Estimated],
            ChargeBasis::Unbilled => [0, ChargeBasis::Unbilled],
            ChargeBasis::Reservation => [$reserved, ChargeBasis::Reservation],
        };
    }

    private function openDay(string $day, CarbonImmutable $now): void
    {
        DB::insert(
            'insert into inference_budget_days (day, limit_microusd, created_at, updated_at) values (?, ?, ?, ?) on conflict (day) do nothing',
            [$day, self::dailyLimit(), $now, $now],
        );
    }

    private function retryAt(CarbonImmutable $now): CarbonImmutable
    {
        $recheck = $now->addMinutes(Config::integer('ripe.budget.recheck_minutes'));
        $tomorrow = self::nextDay($now);

        return $recheck->lessThan($tomorrow) ? $recheck : $tomorrow;
    }
}
