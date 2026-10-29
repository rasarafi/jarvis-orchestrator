<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('parent_task_id')->nullable()
                ->references('id')->on('tasks')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->integer('estimate_minutes')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_task_id');
            $table->dropColumn(['start_date', 'estimate_minutes', 'archived_at']);
        });
    }
};
