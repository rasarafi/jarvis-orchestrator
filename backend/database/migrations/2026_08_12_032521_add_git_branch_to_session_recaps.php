<?php // backend/database/migrations/2026_08_12_032521_add_git_branch_to_session_recaps.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_recaps', function (Blueprint $table) {
            $table->text('git_branch')->nullable()->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('session_recaps', function (Blueprint $table) {
            $table->dropColumn('git_branch');
        });
    }
};
