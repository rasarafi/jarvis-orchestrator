<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two attribution dimensions the index was blind to.
 *
 * 1. SUBAGENTS. Claude Code writes subagent transcripts to
 *    `<project>/<session-uuid>/subagents/agent-*.jsonl`. The scanner only read
 *    `*.jsonl` at the project-dir level, so 1,066 files / 536 MB of real token
 *    spend were never indexed at all — every cost and quota figure undercounted
 *    by the subagent share. Those records carry the PARENT's `sessionId`, so a
 *    subagent row needs a synthetic key: `<parent>:agent:<agentId>`.
 *
 *    `kind` = 'main' | 'subagent'. `parent_session_id` is null for mains.
 *    Rule: token/cost SUMS include both kinds (they are distinct spend, never
 *    duplicated between files); session LISTS and COUNTS use kind = 'main'.
 *
 * 2. ACCOUNT. Two Claude subscriptions (cl / cl2) share one projects/ tree via
 *    a symlink, and nothing in the .jsonl identifies the account. It can only
 *    be captured live, by the SessionStart hook (see tools/session-account-hook/).
 *    `session_accounts` is that mapping; `sessions.account` is the denormalised
 *    copy the scanner stamps at index time. Null = unattributed (everything
 *    before the hook existed) and stays null — there is no signal to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS kind TEXT NOT NULL DEFAULT 'main'");
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS parent_session_id TEXT');
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS account TEXT');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_kind ON sessions.sessions (kind)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_parent ON sessions.sessions (parent_session_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sessions_account ON sessions.sessions (account)');

        DB::statement('
            CREATE TABLE IF NOT EXISTS sessions.session_accounts (
                session_id TEXT PRIMARY KEY,
                account TEXT NOT NULL,
                email TEXT,
                first_seen_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sessions.session_accounts');
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_account');
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_parent');
        DB::statement('DROP INDEX IF EXISTS sessions.idx_sessions_kind');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS account');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS parent_session_id');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS kind');
    }
};
