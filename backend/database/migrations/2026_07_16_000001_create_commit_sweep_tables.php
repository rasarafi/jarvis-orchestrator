<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sweep_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('completed'); // completed | partial | failed
            $table->jsonb('scope')->default('{}');          // {group?: string, project?: slug}
            $table->unsignedSmallInteger('window_days')->default(7);
            $table->string('model');
            $table->jsonb('stats')->default('{}');
            $table->timestamps();
        });

        Schema::create('sweep_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sweep_run_id')->constrained('sweep_runs')->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('commits')->default('[]');        // [{sha, subject, committed_at}]
            $table->string('confidence');
            $table->text('rationale')->nullable();
            $table->string('status')->default('pending');   // pending | confirmed | dismissed
            $table->jsonb('meta')->default('{}');           // {previous_status} captured on confirm, for undo
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index('task_id');
            $table->index(['sweep_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sweep_matches');
        Schema::dropIfExists('sweep_runs');
    }
};
