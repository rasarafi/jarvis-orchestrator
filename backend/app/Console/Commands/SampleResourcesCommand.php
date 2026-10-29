<?php // backend/app/Console/Commands/SampleResourcesCommand.php
namespace App\Console\Commands;

use App\Services\SystemResourceService;
use Illuminate\Console\Command;

class SampleResourcesCommand extends Command
{
    protected $signature = 'jarvis:sample-resources
        {--retain=7 : Days of samples to keep before pruning}';

    protected $description = 'Persist one system-resource sample and prune old rows (scheduled every minute)';

    public function handle(SystemResourceService $service): int
    {
        $sample = $service->sample();
        $pruned = $service->prune((int) $this->option('retain'));

        $this->info("sampled: {$sample->pressure->value} · load {$sample->cpu_load} · {$sample->session_count} sessions · pruned {$pruned}");

        return self::SUCCESS;
    }
}
