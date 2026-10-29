<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split prompt-cache WRITE tokens by TTL tier.
 *
 * Cache writes are not one price: a 5-minute-TTL write bills at 1.25x the
 * model's base input rate, a 1-hour-TTL write at 2x. The scanner collapsed
 * both into one column and priced everything at 1.25x, so every 1h write
 * (which Claude Code uses heavily) was undercounted by 37.5%.
 *
 * cache_creation_tokens stays the TOTAL (unchanged meaning, so existing
 * quota/token queries keep working); the new column carries the 1h PORTION
 * of that total. The 5m portion is total - 1h.
 *
 * Backfill note: historical rows are left at 0 by this migration — the split
 * only exists in the .jsonl, so it is recovered by re-parsing in
 * `jarvis:recompute-costs`, not by SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sessions.messages ADD COLUMN IF NOT EXISTS cache_creation_1h_tokens INTEGER DEFAULT 0');
        DB::statement('ALTER TABLE sessions.sessions ADD COLUMN IF NOT EXISTS total_cache_creation_1h_tokens INTEGER DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sessions.messages DROP COLUMN IF EXISTS cache_creation_1h_tokens');
        DB::statement('ALTER TABLE sessions.sessions DROP COLUMN IF EXISTS total_cache_creation_1h_tokens');
    }
};
