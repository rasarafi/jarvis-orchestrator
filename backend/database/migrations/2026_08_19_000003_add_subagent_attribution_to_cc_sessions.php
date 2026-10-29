<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Part A1 (usage behaviors spec, workspace/26-Cost-Quota/09-usage-behaviors-spec.md).
 *
 * `subagent_type` / `spawn_depth` come from the sidecar `agent-<id>.meta.json`
 * Claude Code writes next to every subagent transcript — no scanner parsing
 * change needed, and unlike account attribution this is fully backfillable
 * (the sidecar has been on disk for the whole history).
 *
 * `entrypoint` is a session-level field ('cli' | 'sdk-cli' | 'sdk-py') already
 * present on every transcript record. It is the observed signal for the spec's
 * `cron` behavior ("no human-authored user message in the session") — verified
 * against real `claude -p` headless transcripts (RecapService/ClaudeCliService),
 * which read entrypoint=sdk-cli, vs. an interactive session's entrypoint=cli.
 * Bundled into this migration because it is captured in the same parse pass,
 * on the same table, as the other two columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS subagent_type TEXT');
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS spawn_depth INT');
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS entrypoint TEXT');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_subagent_type ON sessions.sessions (subagent_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_entrypoint ON sessions.sessions (entrypoint)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_entrypoint');
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_subagent_type');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS entrypoint');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS spawn_depth');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS subagent_type');
    }
};
