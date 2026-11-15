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
        Schema::create('inference_budget_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->bigInteger('limit_microusd');
            $table->bigInteger('reserved_microusd')->default(0);
            $table->bigInteger('spent_microusd')->default(0);
            $table->timestampsTz();
        });

        DB::statement('alter table inference_budget_days add constraint inference_budget_days_amounts_check check (limit_microusd >= 0 and reserved_microusd >= 0 and spent_microusd >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inference_budget_days');
    }
};
