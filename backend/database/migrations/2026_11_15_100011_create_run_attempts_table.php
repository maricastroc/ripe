<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->smallInteger('number');
            $table->uuid('lease_token')->unique();
            $table->string('worker', 120)->nullable();
            $table->string('queue_job_id', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->enum('outcome', ['succeeded', 'failed', 'timed_out', 'invalid_output', 'driver_unavailable', 'rate_limited', 'cancelled', 'lease_lost', 'interrupted'])->nullable();
            $table->string('outcome_detail', 32)->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->string('driver', 64)->nullable();
            $table->string('model', 120)->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('ttft_ms')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->bigInteger('cost_microusd')->nullable();
            $table->bigInteger('reserved_microusd')->default(0);

            $table->unique(['run_id', 'number']);
            $table->unique(['id', 'run_id']);
        });

        DB::statement('alter table run_attempts add constraint run_attempts_number_check check (number >= 1)');
        DB::statement('alter table run_attempts add constraint run_attempts_finish_check check ((outcome is null) = (finished_at is null))');
        DB::statement('alter table run_attempts add constraint run_attempts_metrics_check check (
            coalesce(prompt_tokens, 0) >= 0 and coalesce(completion_tokens, 0) >= 0
            and coalesce(ttft_ms, 0) >= 0 and coalesce(duration_ms, 0) >= 0
            and coalesce(cost_microusd, 0) >= 0 and reserved_microusd >= 0
        )');
        DB::statement('create unique index run_attempts_one_open_per_run_unique on run_attempts (run_id) where finished_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('run_attempts');
    }
};
