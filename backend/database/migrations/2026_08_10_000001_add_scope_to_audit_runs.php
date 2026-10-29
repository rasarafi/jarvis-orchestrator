<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table) {
            // system = handover-audit (whole product); feature = feature-audit (one feature).
            // Existing rows backfill to 'system' via the default — they are all handover runs.
            $table->string('scope')->default('system')->after('project_id');
            $table->string('feature')->nullable()->after('scope'); // feature slug; only set when scope=feature

            $table->index(['project_id', 'scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'scope', 'created_at']);
            $table->dropColumn(['scope', 'feature']);
        });
    }
};
