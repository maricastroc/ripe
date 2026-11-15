<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array COLUMNS = [
        'visitors' => ['last_seen_at', 'created_at', 'updated_at'],
        'tasks' => ['return_at', 'deferred_at', 'interpreted_at', 'planned_at', 'reviewed_at', 'ripe_at', 'harvested_at', 'created_at', 'updated_at'],
        'steps' => ['completed_at', 'created_at', 'updated_at'],
        'questions' => ['resolved_at', 'created_at', 'updated_at'],
        'artifacts' => ['created_at', 'updated_at'],
        'artifact_versions' => ['created_at'],
        'agent_runs' => ['available_at', 'dispatched_at', 'lease_expires_at', 'last_heartbeat_at', 'cancel_requested_at', 'started_at', 'finished_at', 'created_at', 'updated_at'],
        'run_attempts' => ['started_at', 'last_heartbeat_at', 'finished_at'],
        'task_events' => ['occurred_at'],
        'inference_budget_days' => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->precision(6);
    }

    public function down(): void
    {
        $this->precision(0);
    }

    private function precision(int $digits): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $changes = array_map(static fn (string $column): string => "alter column {$column} type timestamp({$digits}) with time zone", $columns);

            DB::statement("alter table {$table} ".implode(', ', $changes));
        }
    }
};
