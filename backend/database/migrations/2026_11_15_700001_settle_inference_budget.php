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
        Schema::table('inference_budget_days', function (Blueprint $table): void {
            $table->bigInteger('estimated_microusd')->default(0)->after('spent_microusd');
        });
        DB::statement('alter table inference_budget_days drop constraint inference_budget_days_amounts_check');
        DB::statement('alter table inference_budget_days add constraint inference_budget_days_amounts_check check (
            limit_microusd >= 0 and reserved_microusd >= 0 and spent_microusd >= 0 and estimated_microusd >= 0
        )');

        Schema::create('visitor_budget_days', function (Blueprint $table): void {
            $table->uuid('visitor_id');
            $table->date('day');
            $table->bigInteger('reserved_microusd')->default(0);
            $table->bigInteger('spent_microusd')->default(0);
            $table->bigInteger('estimated_microusd')->default(0);
            $table->timestampsTz();

            $table->primary(['visitor_id', 'day']);
            $table->foreign('visitor_id')->references('id')->on('visitors')->cascadeOnDelete();
        });
        DB::statement('alter table visitor_budget_days add constraint visitor_budget_days_amounts_check check (
            reserved_microusd >= 0 and spent_microusd >= 0 and estimated_microusd >= 0
        )');

        Schema::table('run_attempts', function (Blueprint $table): void {
            $table->date('budget_day')->nullable()->after('reserved_microusd');
            $table->uuid('budget_visitor_id')->nullable()->after('budget_day');
            $table->timestampTz('settled_at')->nullable()->after('budget_visitor_id');
            $table->bigInteger('charged_microusd')->nullable()->after('settled_at');
            $table->string('charge_basis', 16)->nullable()->after('charged_microusd');

            $table->foreign('budget_visitor_id')->references('id')->on('visitors')->nullOnDelete();
        });
        DB::statement("alter table run_attempts add constraint run_attempts_budget_check check (
            (reserved_microusd > 0) = (budget_day is not null)
            and (settled_at is null) = (charge_basis is null)
            and (settled_at is null) = (charged_microusd is null)
            and coalesce(charged_microusd, 0) >= 0
            and (charge_basis is null or charge_basis in ('reported', 'estimated', 'unbilled', 'reservation'))
        )");
    }

    public function down(): void
    {
        DB::statement('alter table run_attempts drop constraint run_attempts_budget_check');

        Schema::table('run_attempts', function (Blueprint $table): void {
            $table->dropForeign(['budget_visitor_id']);
            $table->dropColumn(['budget_day', 'budget_visitor_id', 'settled_at', 'charged_microusd', 'charge_basis']);
        });

        Schema::drop('visitor_budget_days');

        DB::statement('alter table inference_budget_days drop constraint inference_budget_days_amounts_check');
        DB::statement('alter table inference_budget_days add constraint inference_budget_days_amounts_check check (
            limit_microusd >= 0 and reserved_microusd >= 0 and spent_microusd >= 0
        )');

        Schema::table('inference_budget_days', function (Blueprint $table): void {
            $table->dropColumn('estimated_microusd');
        });
    }
};
