<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('status');                        // running | passed | flagged | blocked
            $table->unsignedTinyInteger('gate_reached')->default(0); // 0 | 1 | 2
            $table->jsonb('app_classification')->default('[]');
            $table->text('summary')->nullable();
            $table->jsonb('meta')->default('{}');            // durations, skill version
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('gate');
            $table->string('module');                        // data-substance | route-sweep | ...
            $table->string('severity');                      // critical | warning | info
            $table->string('title');
            $table->text('detail')->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->string('status')->default('open');       // open | dismissed | converted | suppressed
            $table->string('suppression_key');
            $table->timestampTz('dismissed_at')->nullable();
            $table->foreignUuid('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['audit_run_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('audit_runs');
    }
};
