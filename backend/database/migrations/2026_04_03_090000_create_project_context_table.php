<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_context', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('section');
            $table->text('content');
            $table->timestamps();

            $table->unique(['project_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_context');
    }
};
