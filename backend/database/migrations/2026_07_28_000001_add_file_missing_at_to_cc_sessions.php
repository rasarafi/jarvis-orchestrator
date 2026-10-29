<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turn the CC session mirror into an archive.
 *
 * The scanner used to hard-delete sessions.sessions rows whose .jsonl had
 * rotated off disk, so history was capped at whatever ~/.claude/projects still
 * held (~7 weeks). Sessions are now soft-deleted: file_missing_at is stamped
 * when the file disappears and cleared if it ever comes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS file_missing_at TIMESTAMPTZ NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_file_missing ON sessions.sessions (file_missing_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_file_missing');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS file_missing_at');
    }
};
