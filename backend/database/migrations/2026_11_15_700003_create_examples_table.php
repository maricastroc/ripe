<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examples', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->uuid('task_id')->unique();
            $table->string('checksum', 64);
            $table->string('driver', 32);
            $table->string('model', 120);
            $table->timestampTz('generated_at');
            $table->timestampTz('installed_at');

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::drop('examples');
    }
};
