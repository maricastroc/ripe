<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->enum('origin', ['orchestrator', 'visitor'])->default('orchestrator')->after('kind');
            $table->timestampTz('dispatched_at')->nullable()->after('available_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'dispatched_at']);
        });
    }
};
