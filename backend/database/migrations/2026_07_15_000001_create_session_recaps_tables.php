<?php // backend/database/migrations/2026_07_15_000001_create_session_recaps_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_recaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('cc_session_id')->unique();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');                       // RecapKind
            $table->string('status')->default('ok');      // RecapStatus
            $table->string('model')->nullable();          // CLI alias used to summarize
            $table->text('headline')->nullable();
            $table->jsonb('bullets')->default('[]');
            $table->text('next_action')->nullable();
            $table->jsonb('decisions')->default('[]');
            $table->jsonb('facts')->default('{}');        // {messages, duration_minutes, tasks_closed}
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->timestampTz('summarized_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'ended_at']);
        });

        Schema::create('recap_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('source_recap_id')->constrained('session_recaps')->cascadeOnDelete();
            $table->foreignUuid('resolved_by_recap_id')->nullable()->constrained('session_recaps')->nullOnDelete();
            $table->text('text');
            $table->string('status')->default('open');    // RecapThreadStatus
            $table->timestamps();
            $table->index(['status', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recap_threads');
        Schema::dropIfExists('session_recaps');
    }
};
