<?php

namespace App\Console\Commands;

use App\Services\SessionArchiveImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportSessionArchiveCommand extends Command
{
    protected $signature = 'jarvis:import-session-archive
                            {path : Path to a CC Session Manager sessions.db}
                            {--dry-run : Report what would be imported, write nothing}';

    protected $description = 'Import a legacy CC Session Manager SQLite archive into sessions.*';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $service = new SessionArchiveImportService($path);
            $summary = $service->summary();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Archive: ' . $path);
        $this->line("  projects: {$summary['projects']}");
        $this->line("  sessions: {$summary['sessions']}");
        $this->line("  messages: {$summary['messages']}");
        $this->line("  range:    {$summary['range']}");
        $this->newLine();

        if ($dryRun) {
            $this->comment('Dry run — nothing will be written.');
        }

        $started = microtime(true);

        try {
            $result = $service->import($dryRun);
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->info($dryRun ? 'Would import:' : 'Imported:');
        $this->line("  projects mapped: {$result['projects']}");
        $this->line("  sessions:        {$result['sessions']}");
        $this->line("  messages:        {$result['messages']}");
        $this->line("  timestamps fixed: {$result['backfilled_timestamps']}");
        $this->line("  elapsed:         {$elapsed}s");

        if ($result['skipped']) {
            $this->newLine();
            $this->warn('Skipped ' . count($result['skipped']) . ' row(s):');
            foreach (array_slice($result['skipped'], 0, 20, true) as $key => $reason) {
                $this->line("  {$key}: {$reason}");
            }
        }

        return self::SUCCESS;
    }
}
