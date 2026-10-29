<?php // backend/database/migrations/2026_08_12_032838_backfill_git_branch_on_session_recaps.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** git_branch didn't exist on session_recaps until the previous migration — every row
 *  written before that has it NULL, even though the source data is still sitting in
 *  sessions.sessions.git_branch. Backfill from there so existing recaps group by branch
 *  immediately instead of waiting for the sessions to be re-summarized. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE session_recaps sr
            SET git_branch = s.git_branch
            FROM sessions.sessions s
            WHERE s.session_id = sr.cc_session_id
              AND sr.git_branch IS NULL
              AND s.git_branch IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Backfill is not meaningfully reversible — leave the data in place.
    }
};
