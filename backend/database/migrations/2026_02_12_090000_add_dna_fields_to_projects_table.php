<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('key_modules')->nullable();
            $table->json('run_commands')->nullable();
            $table->text('known_risks')->nullable();
            $table->json('environments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['key_modules', 'run_commands', 'known_risks', 'environments']);
        });
    }
};
