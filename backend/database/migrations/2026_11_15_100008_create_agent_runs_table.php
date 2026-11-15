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
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['interpret', 'plan', 'generate', 'revise', 'review']);
            $table->uuid('step_id')->nullable();
            $table->uuid('artifact_id')->nullable();
            $table->uuid('base_version_id')->nullable();
            $table->text('instruction')->nullable();
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed', 'cancelled'])->default('queued');
            $table->timestampTz('available_at');
            $table->enum('deferred_reason', ['pace', 'backoff', 'rate_limited', 'budget', 'driver_unavailable'])->nullable();
            $table->uuid('lease_token')->nullable()->unique();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->smallInteger('attempt_count')->default(0);
            $table->smallInteger('max_attempts');
            $table->smallInteger('interruption_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'task_id']);
            $table->index('step_id');
            $table->index('artifact_id');
            $table->foreign(['step_id', 'task_id'])->references(['id', 'task_id'])->on('steps')->cascadeOnDelete();
            $table->foreign(['artifact_id', 'task_id'])->references(['id', 'task_id'])->on('artifacts')->cascadeOnDelete();
        });

        DB::statement("alter table agent_runs add constraint agent_runs_target_check check (
            (kind = 'generate' and step_id is not null and artifact_id is null and base_version_id is null)
            or (kind = 'revise' and step_id is null and artifact_id is not null and base_version_id is not null)
            or (kind in ('interpret', 'plan', 'review') and step_id is null and artifact_id is null and base_version_id is null)
        )");
        DB::statement("alter table agent_runs add constraint agent_runs_instruction_check check (instruction is null or kind = 'revise')");
        DB::statement("alter table agent_runs add constraint agent_runs_lease_check check ((status = 'running') = (lease_token is not null) and (lease_token is null) = (lease_expires_at is null))");
        DB::statement("alter table agent_runs add constraint agent_runs_finish_check check ((status in ('succeeded', 'failed', 'cancelled')) = (finished_at is not null))");
        DB::statement("alter table agent_runs add constraint agent_runs_failure_check check ((status = 'failed') = (failure_reason is not null))");
        DB::statement('alter table agent_runs add constraint agent_runs_attempts_check check (max_attempts >= 1 and attempt_count >= 0 and attempt_count <= max_attempts and interruption_count >= 0)');
        DB::statement("create unique index agent_runs_one_running_per_task_unique on agent_runs (task_id) where status = 'running'");
        DB::statement("create unique index agent_runs_one_active_task_run_unique on agent_runs (task_id, kind) where status in ('queued', 'running') and kind in ('interpret', 'plan', 'review')");
        DB::statement("create unique index agent_runs_one_active_step_run_unique on agent_runs (step_id) where status in ('queued', 'running') and kind = 'generate'");
        DB::statement("create unique index agent_runs_one_active_artifact_run_unique on agent_runs (artifact_id) where status in ('queued', 'running') and kind = 'revise'");
        DB::statement("create index agent_runs_due_index on agent_runs (available_at) where status = 'queued'");
        DB::statement("create index agent_runs_leases_index on agent_runs (lease_expires_at) where status = 'running'");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
