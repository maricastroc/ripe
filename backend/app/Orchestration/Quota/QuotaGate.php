<?php

declare(strict_types=1);

namespace App\Orchestration\Quota;

use App\Enums\ChargeBasis;
use App\Enums\QuotaWait;
use App\Inference\QuotaLimits;
use App\Models\RunAttempt;
use App\Orchestration\AttemptReport;
use App\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class QuotaGate
{
    public function admit(string $scope, QuotaLimits $limits, int $demand, CarbonImmutable $now): QuotaTicket|QuotaShortfall
    {
        Transactions::require('Admitting a call against a quota');

        DB::insert('insert into inference_quotas (scope, created_at, updated_at) values (?, ?, ?) on conflict (scope) do nothing', [$scope, $now, $now]);
        $quota = DB::selectOne('select blocked_until, blocked_reason from inference_quotas where scope = ? for update', [$scope]);
        $blocked = self::blockedUntil($quota);

        if ($blocked !== null && $blocked->greaterThan($now)) {
            return new QuotaShortfall(self::reasonOf($quota), $blocked, true);
        }

        $tokens = min($demand, $limits->tokensPerMinute);
        $refusal = $this->refusal($scope, $limits, $tokens, $now);

        if ($refusal !== null) {
            return $refusal;
        }

        $id = (string) Str::uuid();
        DB::insert('insert into inference_calls (id, scope, started_at, reserved_tokens) values (?, ?, ?, ?)', [$id, $scope, $now, $tokens]);

        return new QuotaTicket($id, $scope, $tokens);
    }

    public function attach(QuotaTicket $ticket, RunAttempt $attempt): void
    {
        DB::update('update inference_calls set attempt_id = ? where id = ?', [$attempt->id, $ticket->callId]);
    }

    public function settle(RunAttempt $attempt, AttemptReport $report, CarbonImmutable $now): void
    {
        $call = DB::selectOne('select id, reserved_tokens from inference_calls where attempt_id = ? and finished_at is null', [$attempt->id]);

        if (! $call instanceof stdClass || ! is_numeric($call->reserved_tokens) || ! is_string($call->id)) {
            return;
        }

        $reserved = (int) $call->reserved_tokens;
        $usage = $report->usage;

        $used = match ($report->billing()) {
            ChargeBasis::Reported => $usage === null ? $reserved : $usage->inputTokens - $usage->cachedInputTokens + $usage->outputTokens,
            ChargeBasis::Estimated => $usage === null ? $reserved : max($reserved, $usage->inputTokens + $usage->outputTokens),
            ChargeBasis::Unbilled => 0,
            ChargeBasis::Reservation => $reserved,
        };

        DB::update('update inference_calls set finished_at = ?, used_tokens = ? where id = ? and finished_at is null', [$now, max(0, $used), $call->id]);
    }

    public function block(string $scope, CarbonImmutable $until, QuotaWait $wait, CarbonImmutable $now): void
    {
        DB::insert('insert into inference_quotas (scope, created_at, updated_at) values (?, ?, ?) on conflict (scope) do nothing', [$scope, $now, $now]);
        DB::update(
            'update inference_quotas set
                blocked_reason = case when blocked_until is null or blocked_until < ? then ? else blocked_reason end,
                blocked_until = greatest(coalesce(blocked_until, ?), ?),
                updated_at = ?
             where scope = ?',
            [$until, $wait->value, $until, $until, $now, $scope],
        );
    }

    public function standing(string $scope, QuotaLimits $limits, CarbonImmutable $now): QuotaStanding
    {
        $quota = DB::selectOne('select blocked_until, blocked_reason from inference_quotas where scope = ?', [$scope]);
        $blocked = self::blockedUntil($quota);

        if ($blocked !== null && $blocked->greaterThan($now)) {
            $wait = self::reasonOf($quota);

            return new QuotaStanding($wait, $blocked);
        }

        $refusal = $this->refusal($scope, $limits, min(Config::integer('ripe.quota.typical_call_tokens'), $limits->tokensPerMinute), $now);

        if ($refusal === null || $refusal->wait === QuotaWait::Capacity) {
            return new QuotaStanding(null, null);
        }

        return new QuotaStanding($refusal->wait, $refusal->known ? $refusal->retryAt : null);
    }

    public function closeAbandoned(CarbonImmutable $now): int
    {
        return DB::update(
            'update inference_calls set finished_at = greatest(started_at, ?), used_tokens = reserved_tokens
             where finished_at is null and started_at < ?
             and (attempt_id is null or exists (select 1 from run_attempts where run_attempts.id = inference_calls.attempt_id and run_attempts.finished_at is not null))',
            [$now, $now->subSeconds(Config::integer('ripe.quota.abandoned_after_seconds'))],
        );
    }

    public function prune(CarbonImmutable $now): int
    {
        return DB::table('inference_calls')->where('started_at', '<', $now->subDays(2))->delete();
    }

    private function refusal(string $scope, QuotaLimits $limits, int $tokens, CarbonImmutable $now): ?QuotaShortfall
    {
        $usage = DB::selectOne(
            'select
                count(*) filter (where started_at > :minute) as minute_requests,
                coalesce(sum(coalesce(used_tokens, reserved_tokens)) filter (where started_at > :minute), 0) as minute_tokens,
                count(*) as day_requests,
                coalesce(sum(coalesce(used_tokens, reserved_tokens)), 0) as day_tokens,
                count(*) filter (where finished_at is null and started_at > :stale) as in_flight,
                min(started_at) filter (where started_at > :minute) as minute_oldest
             from inference_calls where scope = :scope and started_at > :day',
            [
                'minute' => $now->subMinute(),
                'stale' => $now->subSeconds(Config::integer('ripe.quota.abandoned_after_seconds')),
                'day' => $now->subDay(),
                'scope' => $scope,
            ],
        );

        $count = static fn (string $field): int => $usage instanceof stdClass && is_numeric($usage->{$field} ?? null) ? (int) $usage->{$field} : 0;

        if ($count('day_requests') + 1 > $limits->requestsPerDay || $count('day_tokens') + $tokens > $limits->tokensPerDay) {
            return new QuotaShortfall(QuotaWait::Daily, $now->addMinutes(Config::integer('ripe.quota.daily_recheck_minutes')), false);
        }

        if ($count('in_flight') + 1 > $limits->concurrency) {
            return new QuotaShortfall(QuotaWait::Capacity, $now->addSeconds(Config::integer('ripe.quota.busy_recheck_seconds')), true);
        }

        if ($count('minute_requests') + 1 > $limits->requestsPerMinute || $count('minute_tokens') + $tokens > $limits->tokensPerMinute) {
            $oldest = $usage instanceof stdClass && is_string($usage->minute_oldest ?? null) ? CarbonImmutable::parse($usage->minute_oldest) : $now;

            return new QuotaShortfall(QuotaWait::Capacity, $oldest->addMinute()->max($now->addSecond()), true);
        }

        return null;
    }

    private static function blockedUntil(mixed $quota): ?CarbonImmutable
    {
        return $quota instanceof stdClass && is_string($quota->blocked_until ?? null) ? CarbonImmutable::parse($quota->blocked_until) : null;
    }

    private static function reasonOf(mixed $quota): QuotaWait
    {
        return $quota instanceof stdClass && is_string($quota->blocked_reason ?? null) ? QuotaWait::tryFrom($quota->blocked_reason) ?? QuotaWait::Capacity : QuotaWait::Capacity;
    }
}
