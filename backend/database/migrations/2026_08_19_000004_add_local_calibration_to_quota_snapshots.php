<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze local spend into each quota reading, making every row a self-contained
 * calibration sample.
 *
 * The open question this exists to answer: can the real percentage be advanced
 * between fetches by extrapolating from local spend? That needs paired
 * observations — (real %, local spend, how stale that local count was) — and
 * they cannot be reconstructed after the fact, because `sessions.messages` has
 * no notion of what it looked like at an earlier moment. So we capture at fetch
 * time or not at all.
 *
 * `local_scan_lag_s` is not optional bookkeeping: the index lags the scan
 * schedule (a 13-minute gap was observed on 2026-08-19 while three real readings
 * moved 74% → 76%), so a pair with a large lag is not evidence of anything and
 * must be excluded from any fit.
 *
 * `local_breakdown` holds per-model token CLASSES (input / output /
 * cache_creation / cache_read) rather than a single weighted number, because the
 * quota's own weighting is unknown. Raw tokens are known to be a bad proxy —
 * 95-98% of them are cache reads while the output share swings ~3x day to day —
 * so the components are stored unweighted and any weighting is fitted later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quota_snapshots', function (Blueprint $table) {
            $table->timestampTz('local_window_started_at')->nullable();
            $table->bigInteger('local_tokens')->nullable();
            $table->decimal('local_cost_usd', 12, 4)->nullable();
            $table->timestampTz('local_indexed_through')->nullable();
            $table->integer('local_scan_lag_s')->nullable();
            $table->jsonb('local_breakdown')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quota_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'local_window_started_at', 'local_tokens', 'local_cost_usd',
                'local_indexed_through', 'local_scan_lag_s', 'local_breakdown',
            ]);
        });
    }
};
