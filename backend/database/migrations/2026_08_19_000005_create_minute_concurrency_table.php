<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Part A3 `high_parallel` behavior. A naive interval-overlap join over the full
 * `messages` corpus does not scale, so concurrency is precomputed at minute
 * resolution (minute bucket -> distinct active session_id count) and the
 * behavior reads off this table instead. Maintained incrementally by the scan
 * (SessionScannerService::rebuildMinuteConcurrency, bounded window) and fully
 * regenerable via `jarvis:rebuild-concurrency`.
 *
 * Also adds the timestamp index `sessions.messages` never had — every range
 * query in this spec (cache_miss, long_context, high_parallel, cron, skill
 * propagation) filters on it, same as CostAnalyticsService already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS sessions.minute_concurrency (
                minute_bucket TIMESTAMPTZ PRIMARY KEY,
                active_sessions INT NOT NULL
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_timestamp ON sessions.messages (timestamp)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions.idx_messages_timestamp');
        DB::statement('DROP TABLE IF EXISTS sessions.minute_concurrency');
    }
};
