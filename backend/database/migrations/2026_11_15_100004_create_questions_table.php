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
        Schema::create('questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('position');
            $table->text('text');
            $table->text('rationale')->default('');
            $table->enum('kind', ['optional', 'essential']);
            $table->enum('status', ['open', 'answered', 'skipped'])->default('open');
            $table->text('answer')->nullable();
            $table->integer('answer_revision')->default(0);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['task_id', 'position']);
            $table->unique(['id', 'task_id']);
            $table->unique(['id', 'task_id', 'kind']);
        });

        DB::statement('alter table questions add constraint questions_position_check check (position >= 0)');
        DB::statement('alter table questions add constraint questions_text_check check (char_length(btrim(text)) > 0)');
        DB::statement("alter table questions add constraint questions_answer_check check ((status = 'answered') = (answer is not null) and (answer is null or char_length(btrim(answer)) > 0))");
        DB::statement("alter table questions add constraint questions_resolution_check check ((status = 'open') = (resolved_at is null))");
        DB::statement("alter table questions add constraint questions_revision_check check (answer_revision >= 0 and (status <> 'answered' or answer_revision >= 1))");
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
