<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array DEFERRALS = ['pace', 'backoff', 'rate_limited', 'budget', 'driver_unavailable'];

    public function up(): void
    {
        Schema::create('agent_settings', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->enum('pace', ['calm', 'steady', 'brisk', 'instant'])->nullable();
            $table->boolean('paused')->default(false);
            $table->string('ollama_model', 120)->nullable();
            $table->timestampTz('updated_at');
        });
        DB::statement('alter table agent_settings add constraint agent_settings_single_row_check check (id = 1)');
        DB::statement('alter table agent_settings add constraint agent_settings_model_check check (ollama_model is null or char_length(btrim(ollama_model)) > 0)');

        $this->allowDeferrals([...self::DEFERRALS, 'paused']);
    }

    public function down(): void
    {
        DB::table('agent_runs')->where('deferred_reason', 'paused')->update(['deferred_reason' => null]);
        $this->allowDeferrals(self::DEFERRALS);

        Schema::drop('agent_settings');
    }

    /** @param list<string> $values */
    private function allowDeferrals(array $values): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'{$value}'::character varying", $values));

        DB::statement('alter table agent_runs drop constraint agent_runs_deferred_reason_check');
        DB::statement("alter table agent_runs add constraint agent_runs_deferred_reason_check check (deferred_reason::text = any (array[{$list}]::text[]))");
    }
};
