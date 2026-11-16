<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array DEFERRALS = ['pace', 'backoff', 'rate_limited', 'budget', 'driver_unavailable', 'paused'];

    public function up(): void
    {
        Schema::create('inference_quotas', function (Blueprint $table): void {
            $table->string('scope', 160)->primary();
            $table->timestampTz('blocked_until')->nullable();
            $table->string('blocked_reason', 16)->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table inference_quotas add constraint inference_quotas_block_check check (
            (blocked_until is null) = (blocked_reason is null) and (blocked_reason is null or blocked_reason in ('capacity', 'daily'))
        )");

        Schema::create('inference_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 160);
            $table->uuid('attempt_id')->nullable()->unique();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('reserved_tokens');
            $table->integer('used_tokens')->nullable();

            $table->index(['scope', 'started_at']);
            $table->foreign('attempt_id')->references('id')->on('run_attempts')->nullOnDelete();
        });
        DB::statement('alter table inference_calls add constraint inference_calls_tokens_check check (
            reserved_tokens >= 0 and coalesce(used_tokens, 0) >= 0 and (finished_at is null or finished_at >= started_at)
        )');

        $this->allowDeferrals([...self::DEFERRALS, 'quota']);
    }

    public function down(): void
    {
        DB::table('agent_runs')->where('deferred_reason', 'quota')->update(['deferred_reason' => 'rate_limited']);
        $this->allowDeferrals(self::DEFERRALS);
        Schema::drop('inference_calls');
        Schema::drop('inference_quotas');
    }

    /** @param list<string> $values */
    private function allowDeferrals(array $values): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'{$value}'::character varying", $values));

        DB::statement('alter table agent_runs drop constraint agent_runs_deferred_reason_check');
        DB::statement("alter table agent_runs add constraint agent_runs_deferred_reason_check check (deferred_reason::text = any (array[{$list}]::text[]))");
    }
};
