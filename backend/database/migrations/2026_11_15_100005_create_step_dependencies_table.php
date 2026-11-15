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
        Schema::create('step_dependencies', function (Blueprint $table): void {
            $table->uuid('step_id');
            $table->uuid('depends_on_step_id');
            $table->uuid('task_id');

            $table->primary(['step_id', 'depends_on_step_id']);
            $table->index('depends_on_step_id');
            $table->foreign(['step_id', 'task_id'])->references(['id', 'task_id'])->on('steps')->cascadeOnDelete();
            $table->foreign(['depends_on_step_id', 'task_id'])->references(['id', 'task_id'])->on('steps')->cascadeOnDelete();
        });

        DB::statement('alter table step_dependencies add constraint step_dependencies_self_check check (step_id <> depends_on_step_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('step_dependencies');
    }
};
