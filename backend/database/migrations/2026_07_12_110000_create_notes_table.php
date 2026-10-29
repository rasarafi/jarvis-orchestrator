<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('source')->default('deck');
            $table->string('status')->default('inbox');
            $table->boolean('pinned')->default(false);
            // remote quick_note ids are UUIDs on the remote side — store as string
            $table->string('remote_id')->nullable()->unique();
            $table->foreignUuid('converted_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignUuid('promoted_knowledge_id')->nullable()->constrained('knowledge')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
