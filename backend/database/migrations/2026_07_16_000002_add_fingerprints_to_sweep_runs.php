<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sweep_runs', function (Blueprint $table) {
            // project_id => hash of (open task set, commit shas, model) analyzed
            // for that project. Lets the next run skip the LLM call when a
            // project's inputs are identical (fingerprint cache).
            $table->jsonb('fingerprints')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('sweep_runs', function (Blueprint $table) {
            $table->dropColumn('fingerprints');
        });
    }
};
