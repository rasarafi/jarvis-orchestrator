<?php

namespace App\Console\Commands;

use App\Services\RemoteSyncService;
use Illuminate\Console\Command;

class SyncRemoteCommand extends Command
{
    protected $signature = 'jarvis:sync-remote {direction=both : push, pull, or both}';
    protected $description = 'Sync data with Jarvis Remote (VPS)';

    public function handle(RemoteSyncService $syncService): int
    {
        $direction = $this->argument('direction');
        $hadFailure = false;

        if (in_array($direction, ['push', 'both'])) {
            $this->info('Pushing to remote...');
            try {
                $results = $syncService->push();
                foreach ($results as $table => $result) {
                    if ($result['status'] === 'error') {
                        $hadFailure = true;
                        $this->error("  {$table}: FAILED — {$result['error']}");
                    } else {
                        $this->line("  {$table}: {$result['affected']} records");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Push failed: {$e->getMessage()}");
                $hadFailure = true;
                if ($direction === 'push') {
                    return Command::FAILURE;
                }
            }
        }

        if (in_array($direction, ['pull', 'both'])) {
            $this->info('Pulling from remote...');
            try {
                $results = $syncService->pull();
                foreach ($results as $table => $count) {
                    $this->line("  {$table}: {$count} records");
                }
            } catch (\Exception $e) {
                $this->error("Pull failed: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        if ($hadFailure) {
            $this->error('Sync completed with failures — see above.');
            return Command::FAILURE;
        }

        $this->info('Sync complete.');
        return Command::SUCCESS;
    }
}
