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
        Schema::table('run_attempts', function (Blueprint $table): void {
            $table->integer('cached_prompt_tokens')->nullable()->after('prompt_tokens');
            $table->integer('cache_write_tokens')->nullable()->after('cached_prompt_tokens');
            $table->integer('reasoning_tokens')->nullable()->after('completion_tokens');
            $table->boolean('usage_estimated')->default(false)->after('reasoning_tokens');
            $table->string('provider_request_id', 128)->nullable()->after('model');
            $table->string('provider_response_id', 128)->nullable()->after('provider_request_id');
        });

        DB::statement('alter table run_attempts add constraint run_attempts_usage_details_check check (
            coalesce(cached_prompt_tokens, 0) >= 0 and coalesce(cache_write_tokens, 0) >= 0 and coalesce(reasoning_tokens, 0) >= 0
        )');
    }

    public function down(): void
    {
        DB::statement('alter table run_attempts drop constraint run_attempts_usage_details_check');

        Schema::table('run_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'cached_prompt_tokens', 'cache_write_tokens', 'reasoning_tokens',
                'usage_estimated', 'provider_request_id', 'provider_response_id',
            ]);
        });
    }
};
