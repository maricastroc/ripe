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
        Schema::create('task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('sequence');
            $table->string('type', 64);
            $table->enum('actor', ['visitor', 'agent', 'system']);
            $table->uuid('run_id')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->jsonb('payload')->default(new Expression("'{}'::jsonb"));
            $table->timestampTz('occurred_at');

            $table->unique(['task_id', 'sequence']);
            $table->index('run_id');
            $table->foreign(['run_id', 'task_id'])->references(['id', 'task_id'])->on('agent_runs')->cascadeOnDelete();
            $table->foreign(['attempt_id', 'run_id'])->references(['id', 'run_id'])->on('run_attempts')->cascadeOnDelete();
        });

        DB::statement('alter table task_events add constraint task_events_sequence_check check (sequence >= 1)');
        DB::statement("alter table task_events add constraint task_events_type_check check (type ~ '^[a-z]+(\\.[a-z_]+)+$')");
        DB::statement("alter table task_events add constraint task_events_payload_check check (jsonb_typeof(payload) = 'object')");
        DB::statement('alter table task_events add constraint task_events_attempt_check check (attempt_id is null or run_id is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
    }
};
