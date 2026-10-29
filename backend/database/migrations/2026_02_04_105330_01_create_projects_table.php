<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('purpose');
            $table->json('stack');
            $table->json('ports')->nullable()->default('{}');
            $table->string('repo_url')->nullable();
            $table->string('local_path');
            $table->enum('status', ['active', 'paused', 'maintenance', 'archived'])->default('active');
            $table->string('current_focus')->nullable();
            $table->string('blockers')->nullable();
            $table->timestamp('last_session_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
