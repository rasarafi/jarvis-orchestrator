<?php // backend/app/Console/Commands/RecomputeCostsCommand.php
namespace App\Console\Commands;

use App\Services\SessionScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-price historical sessions after a MODEL_RATES or cache-tier change.
 *
 * Two tiers, because the cache TTL split only exists in the .jsonl:
 *
 *  - Sessions whose file is still on disk are RE-PARSED (clear file_mtime so
 *    the scanner's skip-if-unchanged gate reopens them). Exact result.
 *  - Sessions whose file has rotated away are recomputed in SQL from the token
 *    columns already stored, treating all cache writes as 5-minute. Fixes the
 *    unpriced-model gap; leaves a small known undercount on 1h writes that no
 *    longer has a source to recover from.
 */
class RecomputeCostsCommand extends Command
{
    protected $signature = 'jarvis:recompute-costs
        {--dry-run : Report what would change without writing}
        {--skip-rescan : Only recompute archived sessions in SQL; do not re-parse .jsonl files}';

    protected $description = 'Re-price session costs after a pricing or cache-tier change';

    public function handle(SessionScannerService $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $before = (float) DB::table('sessions.sessions')->sum('estimated_cost_usd');
        $this->info(sprintf('Current total: $%s', number_format($before, 2)));

        // --- Tier A: re-parse sessions whose .jsonl still exists -------------
        if (!$this->option('skip-rescan')) {
            $onDisk = DB::table('sessions.sessions')->whereNull('file_missing_at')->count();

            if ($dryRun) {
                $this->line("  would re-parse {$onDisk} session file(s)");
            } else {
                $this->line("  re-parsing {$onDisk} session file(s)…");
                // Reopen the scanner's mtime gate so every present file is re-read.
                DB::table('sessions.sessions')->whereNull('file_missing_at')->update(['file_mtime' => 0]);
                $totals = $scanner->scanAll();
                $this->line("  rescan: {$totals['updated']} updated, {$totals['added']} added, {$totals['skipped']} skipped");

                foreach ($scanner->unpricedModels() as $model => $count) {
                    $this->warn("  unpriced model '{$model}' — {$count} message(s) still at \$0");
                }
            }
        }

        // --- Tier B: SQL recompute for archived sessions ---------------------
        $archived = $this->recomputeArchived($dryRun);
        $this->line("  archived sessions recomputed in SQL: {$archived}");

        $after = (float) DB::table('sessions.sessions')->sum('estimated_cost_usd');
        $this->info(sprintf(
            'New total: $%s (%+.2f)',
            number_format($after, 2),
            $after - $before
        ));

        return self::SUCCESS;
    }

    /**
     * Recompute cost for sessions with no file on disk, from stored tokens.
     * All cache writes are priced at the 5-minute rate — the TTL split is
     * unrecoverable once the .jsonl is gone.
     */
    private function recomputeArchived(bool $dryRun): int
    {
        $rates = SessionScannerService::modelRates();

        // Build a VALUES list so Postgres does the per-message join itself.
        $values = [];
        foreach ($rates as $model => [$input, $output]) {
            $values[] = sprintf("('%s', %F, %F)", str_replace("'", "''", $model), $input, $output);
        }
        $valuesSql = implode(', ', $values);

        $sql = "
            WITH rates(model, input_rate, output_rate) AS (VALUES {$valuesSql}),
            totals AS (
                SELECT m.session_id,
                       SUM(
                           coalesce(m.input_tokens, 0) / 1000000.0 * r.input_rate
                         + coalesce(m.output_tokens, 0) / 1000000.0 * r.output_rate
                         + coalesce(m.cache_creation_tokens, 0) / 1000000.0 * r.input_rate * "
                             . SessionScannerService::CACHE_WRITE_5M_MULTIPLIER . "
                         + coalesce(m.cache_read_tokens, 0) / 1000000.0 * r.input_rate * "
                             . SessionScannerService::CACHE_READ_MULTIPLIER . "
                       ) AS cost
                FROM sessions.messages m
                JOIN rates r ON r.model = m.model
                JOIN sessions.sessions s ON s.session_id = m.session_id
                WHERE s.file_missing_at IS NOT NULL
                GROUP BY m.session_id
            )
            UPDATE sessions.sessions s
            SET estimated_cost_usd = round(totals.cost::numeric, 4)
            FROM totals
            WHERE s.session_id = totals.session_id
              AND s.estimated_cost_usd IS DISTINCT FROM round(totals.cost::numeric, 4)
        ";

        if ($dryRun) {
            $count = DB::table('sessions.sessions')->whereNotNull('file_missing_at')->count();
            $this->line("  would recompute up to {$count} archived session(s)");
            return 0;
        }

        return DB::affectingStatement($sql);
    }
}
