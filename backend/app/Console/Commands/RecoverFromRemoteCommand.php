<?php

namespace App\Console\Commands;

use App\Services\RemoteSyncService;
use Illuminate\Console\Command;

class RecoverFromRemoteCommand extends Command
{
    protected $signature = 'jarvis:recover-from-remote {--force : Skip the confirmation prompt}';

    protected $description = 'Disaster recovery — pull projects, project_context, tasks, daily_plans, and interrupts from the remote VPS. Idempotent; existing records only update when the remote version is newer.';

    public function handle(RemoteSyncService $syncService): int
    {
        if (!$this->option('force') && !$this->confirm('This will pull ALL projects, project_context, tasks, daily_plans, and interrupts from the remote. Existing local rows update only if the remote is newer. Continue?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $this->info('Recovering from remote...');
        try {
            $results = $syncService->recoverFromRemote();
            foreach ($results as $table => $count) {
                $this->line("  {$table}: {$count} records");
            }
        } catch (\Exception $e) {
            $this->error("Recovery failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->info('Recovery complete.');
        return Command::SUCCESS;
    }
}
