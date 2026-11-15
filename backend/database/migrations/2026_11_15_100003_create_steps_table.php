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
        Schema::create('steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('position');
            $table->string('title', 90);
            $table->text('description');
            $table->enum('output_format', ['outline', 'draft', 'checklist', 'schedule', 'message', 'notes', 'slides']);
            $table->enum('status', ['blocked', 'ready', 'done'])->default('ready');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['task_id', 'position']);
            $table->unique(['id', 'task_id']);
        });

        DB::statement('alter table steps add constraint steps_position_check check (position >= 0)');
        DB::statement('alter table steps add constraint steps_title_check check (char_length(btrim(title)) > 0)');
        DB::statement("alter table steps add constraint steps_completion_check check ((status = 'done') = (completed_at is not null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('steps');
    }
};
