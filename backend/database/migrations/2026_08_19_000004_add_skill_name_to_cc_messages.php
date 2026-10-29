<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Part A2 (usage behaviors spec). Per-message skill label — Option C storage
 * (per-message, window chosen at query time) from `input.skill` on `Skill`
 * tool_use blocks. No backfill: unlike A1's subagent sidecars, this data does
 * not exist on disk before capture starts, same as the account hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sessions.messages ADD COLUMN IF NOT EXISTS skill_name TEXT');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_skill_name ON sessions.messages (skill_name)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions.idx_messages_skill_name');
        DB::statement('ALTER TABLE sessions.messages DROP COLUMN IF EXISTS skill_name');
    }
};
