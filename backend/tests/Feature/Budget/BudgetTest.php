<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\ChargeBasis;
use App\Enums\RunDeferral;
use App\Inference\Usage;
use App\Models\InferenceBudgetDay;
use App\Models\RunAttempt;
use App\Orchestration\AgentHalt;
use App\Orchestration\AttemptReport;
use App\Orchestration\Execution\RunExecutor;
use App\Orchestration\Sweeper;
use Carbon\CarbonImmutable;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

it('never reserves past the daily limit or a visitor share when workers race', function (): void {
    InferenceBudgetDay::query()->create(['day' => CarbonImmutable::now()->utc()->toDateString(), 'limit_microusd' => 1000]);
    $tasks = collect(range(1, 4))->map(static fn (): string => plantTask()->id);
    $startAt = (string) (microtime(true) + 1.5);

    $results = Process::pool(function (Pool $pool) use ($tasks, $startAt): void {
        foreach ($tasks as $task) {
            foreach (range(1, 2) as $process) {
                $pool->path(base_path())->env(['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'CACHE_STORE' => 'array', 'RIPE_VISITOR_BUDGET_SHARE' => '0.5'])
                    ->command([PHP_BINARY, 'tests/Support/reserve-budget.php', $task, '300', $startAt]);
            }
        }
    })->start()->wait();

    $reserved = collect($results)->filter(static fn ($result): bool => trim($result->output()) === 'reserved')->count();
    $visitors = DB::table('visitor_budget_days')->pluck('reserved_microusd')->map(static fn (mixed $amount): int => (int) $amount);

    expect($reserved)->toBe(3)
        ->and(InferenceBudgetDay::query()->sole()->reserved_microusd)->toBe(900)
        ->and($visitors->max())->toBe(300)
        ->and($visitors->sum())->toBe(900);
});

it('settles each reservation once, on the day it was made, keeping estimates apart from confirmed costs', function (): void {
    config(['ripe.inference.allow_paid' => true, 'ripe.budget.daily_usd' => 1.0, 'ripe.budget.visitor_share' => 1.0]);
    fakeAgent()->priced(2000, 700);
    Date::setTestNow(CarbonImmutable::parse('2026-11-15 23:59:00', 'UTC'));
    $claims = collect(range(1, 4))->map(static fn () => app(RunExecutor::class)->claim(interpretRun(plantTask())->id) ?? throw new RuntimeException('Not claimed.'));

    Date::setTestNow(CarbonImmutable::parse('2026-11-16 00:00:05', 'UTC'));
    $finish = static function (RunAttempt $attempt, AttemptReport $report): bool {
        return DB::transaction(static fn (): bool => $attempt->refresh()->finish(AttemptOutcome::Succeeded, CarbonImmutable::now(), $report));
    };
    $finish($claims[0]->attempt, new AttemptReport(usage: new Usage(400, 120), costMicrodollars: 700, requestId: 'req_1'));
    $finish($claims[1]->attempt, new AttemptReport(usage: new Usage(400, 60, estimated: true), costMicrodollars: 400));
    $finish($claims[2]->attempt, new AttemptReport(requestId: 'req_refused'));

    expect($finish($claims[0]->attempt, new AttemptReport(usage: new Usage(400, 120), costMicrodollars: 900, requestId: 'req_1')))->toBeFalse();

    travelSeconds(120);
    app(Sweeper::class)->sweep();
    app(Sweeper::class)->sweep();

    $attempts = RunAttempt::query()->whereIn('id', $claims->map(static fn ($claim): string => $claim->attempt->id))->get()->keyBy('id');
    $basis = $claims->map(static fn ($claim): array => [$attempts[$claim->attempt->id]->charge_basis, $attempts[$claim->attempt->id]->charged_microusd])->all();
    $day = InferenceBudgetDay::query()->find('2026-11-15');

    expect($basis)->toBe([[ChargeBasis::Reported, 700], [ChargeBasis::Estimated, 400], [ChargeBasis::Unbilled, 0], [ChargeBasis::Reservation, 2000]])
        ->and($day?->reserved_microusd)->toBe(0)
        ->and($day?->spent_microusd)->toBe(700)
        ->and($day?->estimated_microusd)->toBe(2400)
        ->and(InferenceBudgetDay::query()->find('2026-11-16'))->toBeNull()
        ->and(DB::table('visitor_budget_days')->sum('reserved_microusd'))->toBe(0)
        ->and((int) DB::table('visitor_budget_days')->sum('estimated_microusd'))->toBe(2400);
});

it('never lets the public greenhouse call a paid model unless it is explicitly allowed', function (): void {
    $priced = fakeAgent()->priced(2000, 700);
    $run = interpretRun(plantTask());

    expect(app(RunExecutor::class)->claim($run->id))->toBeNull()
        ->and($priced->sent())->toBe([])
        ->and($run->refresh()->deferred_reason)->toBe(RunDeferral::DriverUnavailable)
        ->and(app(AgentHalt::class)->isHalted())->toBeTrue()
        ->and(RunAttempt::query()->count())->toBe(0);
});
