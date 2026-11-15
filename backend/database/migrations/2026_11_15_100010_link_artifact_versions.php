<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->foreign(['current_version_id', 'id'], 'artifacts_current_version_foreign')
                ->references(['id', 'artifact_id'])
                ->on('artifact_versions');
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->foreign(['base_version_id', 'artifact_id'], 'agent_runs_base_version_foreign')
                ->references(['id', 'artifact_id'])
                ->on('artifact_versions');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropForeign('agent_runs_base_version_foreign');
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropForeign('artifacts_current_version_foreign');
        });
    }
};
