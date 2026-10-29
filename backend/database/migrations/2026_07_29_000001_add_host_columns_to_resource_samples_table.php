<?php // backend/database/migrations/2026_07_29_000001_add_host_columns_to_resource_samples_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Windows-host trend alongside the existing WSL trend. All nullable: rows
     * written before the tray agent existed (and any written while it is down)
     * legitimately have no host reading, and the sparkline skips those points.
     */
    public function up(): void
    {
        Schema::table('resource_samples', function (Blueprint $table) {
            $table->decimal('host_mem_pct', 5, 1)->nullable()->after('pressure');
            $table->decimal('host_commit_pct', 5, 1)->nullable()->after('host_mem_pct');
            $table->string('host_pressure')->nullable()->after('host_commit_pct'); // ResourcePressure
        });
    }

    public function down(): void
    {
        Schema::table('resource_samples', function (Blueprint $table) {
            $table->dropColumn(['host_mem_pct', 'host_commit_pct', 'host_pressure']);
        });
    }
};
