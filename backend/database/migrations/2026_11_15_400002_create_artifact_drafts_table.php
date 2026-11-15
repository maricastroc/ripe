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
        Schema::create('artifact_drafts', function (Blueprint $table): void {
            $table->uuid('artifact_id')->primary();
            $table->uuid('base_version_id');
            $table->text('content');
            $table->integer('revision');
            $table->timestampsTz();

            $table->foreign('artifact_id')->references('id')->on('artifacts')->cascadeOnDelete();
            $table->foreign(['base_version_id', 'artifact_id'])->references(['id', 'artifact_id'])->on('artifact_versions')->cascadeOnDelete();
        });
        DB::statement('alter table artifact_drafts add constraint artifact_drafts_content_check check (char_length(content) <= 60000)');
        DB::statement('alter table artifact_drafts add constraint artifact_drafts_revision_check check (revision >= 1)');
    }

    public function down(): void
    {
        Schema::drop('artifact_drafts');
    }
};
