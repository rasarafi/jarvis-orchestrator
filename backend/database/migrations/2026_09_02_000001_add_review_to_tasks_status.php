<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `review` is a fifth open task status, set deliberately from the task board's
 * bulk bar. The tasks table was created with $table->enum(), which on Postgres is
 * a varchar + CHECK constraint — widening it means recreating that constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status::text = ANY (ARRAY['todo', 'in_progress', 'blocked', 'review', 'done']::text[]))");
    }

    public function down(): void
    {
        DB::statement("UPDATE tasks SET status = 'in_progress' WHERE status = 'review'");
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status::text = ANY (ARRAY['todo', 'in_progress', 'blocked', 'done']::text[]))");
    }
};
