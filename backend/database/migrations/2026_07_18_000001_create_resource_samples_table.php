<?php // backend/database/migrations/2026_07_18_000001_create_resource_samples_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('cpu_load', 6, 2);            // 1-min load average
            $table->smallInteger('cores');
            $table->integer('mem_used_mb');
            $table->integer('mem_total_mb');
            $table->integer('swap_used_mb');
            $table->integer('swap_total_mb');
            $table->smallInteger('session_count');        // active claude processes
            $table->string('pressure');                   // ResourcePressure
            $table->timestampTz('sampled_at');
            $table->timestamps();
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_samples');
    }
};
