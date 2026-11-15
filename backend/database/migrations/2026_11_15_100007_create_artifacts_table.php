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
        Schema::create('artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->uuid('step_id')->nullable()->unique();
            $table->enum('kind', ['step', 'review']);
            $table->string('title', 90);
            $table->uuid('current_version_id')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'task_id']);
            $table->foreign(['step_id', 'task_id'])->references(['id', 'task_id'])->on('steps')->cascadeOnDelete();
        });

        DB::statement("alter table artifacts add constraint artifacts_step_link_check check ((kind = 'step') = (step_id is not null))");
        DB::statement('alter table artifacts add constraint artifacts_title_check check (char_length(btrim(title)) > 0)');
        DB::statement("create unique index artifacts_one_review_per_task_unique on artifacts (task_id) where kind = 'review'");
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
