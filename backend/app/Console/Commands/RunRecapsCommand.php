<?php // backend/app/Console/Commands/RunRecapsCommand.php
namespace App\Console\Commands;

use App\Enums\RecapModel;
use App\Services\RecapService;
use Illuminate\Console\Command;

class RunRecapsCommand extends Command
{
    protected $signature = 'jarvis:run-recaps
        {--model=haiku : Summarizer model (haiku|sonnet|opus)}
        {--limit= : Max sessions this run (defaults to services.claude.concurrency)}';

    protected $description = 'Summarize pending Claude Code sessions into recaps (scheduled auto-run)';

    public function handle(RecapService $recapService): int
    {
        $result = $recapService->run(
            RecapModel::from($this->option('model')),
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
            null,
        );

        $this->info("recaps: processed {$result['processed']}, {$result['failed']} failed, {$result['remaining']} remaining");

        return self::SUCCESS;
    }
}
