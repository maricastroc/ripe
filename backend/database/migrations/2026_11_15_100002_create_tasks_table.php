<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_example')->default(false);
            $table->text('prompt');
            $table->string('title', 80);
            $table->string('language', 35)->nullable();
            $table->enum('status', ['dormant', 'ripening', 'waiting', 'ripe', 'stalled', 'harvested'])->default('dormant');
            $table->enum('deliverable', ['document', 'plan', 'presentation', 'message', 'checklist', 'other'])->nullable();
            $table->enum('fruit', ['persimmon', 'plum', 'lemon', 'fig'])->nullable();
            $table->text('outcome')->nullable();
            $table->jsonb('context')->default(new Expression("'[]'::jsonb"));
            $table->jsonb('assumptions')->default(new Expression("'[]'::jsonb"));
            $table->timestampTz('return_at')->nullable();
            $table->timestampTz('deferred_at')->nullable();
            $table->timestampTz('interpreted_at')->nullable();
            $table->timestampTz('planned_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('ripe_at')->nullable();
            $table->timestampTz('harvested_at')->nullable();
            $table->text('stall_reason')->nullable();
            $table->bigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['visitor_id', 'created_at']);
        });

        DB::statement('alter table tasks add constraint tasks_owner_check check ((is_example and visitor_id is null) or (not is_example and visitor_id is not null))');
        DB::statement('alter table tasks add constraint tasks_prompt_check check (char_length(btrim(prompt)) >= 3 and char_length(prompt) <= 2000)');
        DB::statement('alter table tasks add constraint tasks_title_check check (char_length(btrim(title)) > 0)');
        DB::statement("alter table tasks add constraint tasks_context_check check (jsonb_typeof(context) = 'array')");
        DB::statement("alter table tasks add constraint tasks_assumptions_check check (jsonb_typeof(assumptions) = 'array')");
        DB::statement('alter table tasks add constraint tasks_version_check check (version >= 0)');
        DB::statement('alter table tasks add constraint tasks_milestones_check check ((planned_at is null or interpreted_at is not null) and (reviewed_at is null or planned_at is not null) and (ripe_at is null or reviewed_at is not null))');
        DB::statement('alter table tasks add constraint tasks_interpretation_check check (interpreted_at is null or (deliverable is not null and fruit is not null))');
        DB::statement("alter table tasks add constraint tasks_ripe_check check (status <> 'ripe' or (reviewed_at is not null and ripe_at is not null))");
        DB::statement("alter table tasks add constraint tasks_harvest_check check ((status = 'harvested') = (harvested_at is not null))");
        DB::statement("alter table tasks add constraint tasks_stall_check check ((status = 'stalled') = (stall_reason is not null))");
        DB::statement("create index tasks_active_index on tasks (status) where status in ('ripening', 'waiting')");
        DB::statement('create index tasks_examples_index on tasks (created_at) where is_example');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
