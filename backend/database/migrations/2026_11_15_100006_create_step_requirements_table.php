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
        Schema::create('step_requirements', function (Blueprint $table): void {
            $table->uuid('step_id');
            $table->uuid('question_id');
            $table->uuid('task_id');
            $table->string('question_kind', 16)->default('essential');

            $table->primary(['step_id', 'question_id']);
            $table->index('question_id');
            $table->foreign(['step_id', 'task_id'])->references(['id', 'task_id'])->on('steps')->cascadeOnDelete();
            $table->foreign(['question_id', 'task_id', 'question_kind'])->references(['id', 'task_id', 'kind'])->on('questions')->cascadeOnDelete();
        });

        DB::statement("alter table step_requirements add constraint step_requirements_essential_check check (question_kind = 'essential')");
    }

    public function down(): void
    {
        Schema::dropIfExists('step_requirements');
    }
};
