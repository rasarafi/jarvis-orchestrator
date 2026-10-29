<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_plans', function (Blueprint $table) {
            $table->boolean('must_do_completed')->default(false);
            $table->boolean('should_do_completed')->default(false);
            $table->boolean('could_do_completed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('daily_plans', function (Blueprint $table) {
            $table->dropColumn(['must_do_completed', 'should_do_completed', 'could_do_completed']);
        });
    }
};
