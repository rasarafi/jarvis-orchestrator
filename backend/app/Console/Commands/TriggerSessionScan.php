<?php

namespace App\Console\Commands;

use App\Services\SessionScannerService;
use Illuminate\Console\Command;

class TriggerSessionScan extends Command
{
    protected $signature = 'jarvis:scan-sessions {--project= : Scan specific project directory only}';
    protected $description = 'Scan Claude Code session files and index into the sessions schema';

    public function handle(SessionScannerService $scanner): int
    {
        $projectDir = $this->option('project');

        try {
            if ($projectDir) {
                $this->info("Scanning project: {$projectDir}");
                $result = $scanner->scanProject($projectDir);
            } else {
                $this->info('Running full session scan...');
                $result = $scanner->scanAll();
            }

            $this->info("Added: {$result['added']}, Updated: {$result['updated']}, Skipped: {$result['skipped']}, Removed: {$result['removed']}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Scan failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
