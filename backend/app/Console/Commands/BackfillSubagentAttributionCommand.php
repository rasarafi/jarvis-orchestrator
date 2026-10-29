<?php

namespace App\Console\Commands;

use App\Services\SessionScannerService;
use Illuminate\Console\Command;

/**
 * Part A1 one-shot backfill. subagent_type/spawn_depth/entrypoint are all
 * derivable from files already on disk, but a normal reindex will never touch
 * them: `indexSession()` skips a file whose mtime hasn't changed. Run once
 * after the migration lands, then the scanner keeps new sessions current.
 */
class BackfillSubagentAttributionCommand extends Command
{
    protected $signature = 'jarvis:backfill-subagent-attribution';
    protected $description = 'Backfill subagent_type/spawn_depth (sidecar) and entrypoint on already-indexed sessions';

    public function handle(SessionScannerService $scanner): int
    {
        $this->info('Backfilling subagent attribution...');
        $result = $scanner->backfillAttribution();

        $this->info("subagent_type: {$result['subagent_type_updated']} updated, {$result['subagent_type_missing_sidecar']} had no sidecar");
        $this->info("entrypoint: {$result['entrypoint_updated']} updated, {$result['entrypoint_not_found']} not found in file");

        return self::SUCCESS;
    }
}
