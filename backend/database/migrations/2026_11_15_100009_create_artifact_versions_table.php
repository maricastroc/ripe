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
        Schema::create('artifact_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('artifact_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->text('content');
            $table->char('content_hash', 64);
            $table->enum('source', ['generate', 'revise', 'review', 'user_edit', 'restore']);
            $table->foreignUuid('run_id')->nullable()->unique()->constrained('agent_runs')->noActionOnDelete();
            $table->uuid('parent_version_id')->nullable();
            $table->text('instruction')->nullable();
            $table->jsonb('inputs')->default(new Expression("'{}'::jsonb"));
            $table->string('model', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['artifact_id', 'number']);
            $table->unique(['id', 'artifact_id']);
            $table->foreign(['parent_version_id', 'artifact_id'])->references(['id', 'artifact_id'])->on('artifact_versions');
        });

        DB::statement('alter table artifact_versions add constraint artifact_versions_number_check check (number >= 1)');
        DB::statement('alter table artifact_versions add constraint artifact_versions_content_check check (char_length(content) <= 60000)');
        DB::statement("alter table artifact_versions add constraint artifact_versions_content_hash_check check (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('alter table artifact_versions add constraint artifact_versions_parent_check check ((number = 1) = (parent_version_id is null) and (parent_version_id is null or parent_version_id <> id))');
        DB::statement("alter table artifact_versions add constraint artifact_versions_run_link_check check ((source in ('generate', 'revise', 'review')) = (run_id is not null))");
        DB::statement("alter table artifact_versions add constraint artifact_versions_instruction_check check (instruction is null or source = 'revise')");
        DB::statement("alter table artifact_versions add constraint artifact_versions_inputs_check check (jsonb_typeof(inputs) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_versions');
    }
};
