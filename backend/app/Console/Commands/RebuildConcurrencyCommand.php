<?php

namespace App\Console\Commands;

use App\Services\SessionScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Full (or bounded) rebuild of `sessions.minute_concurrency`, the table
 * `high_parallel` reads. The scan itself only maintains the last 2 days
 * incrementally (see SessionScannerService::rebuildMinuteConcurrency) — this
 * is the "regenerate from messages at any time" escape hatch the spec asks
 * for, and what seeds the table's full history the first time.
 */
class RebuildConcurrencyCommand extends Command
{
    protected $signature = 'jarvis:rebuild-concurrency {--since= : ISO date to rebuild from; omit for full history}';
    protected $description = 'Rebuild sessions.minute_concurrency from sessions.messages';

    public function handle(SessionScannerService $scanner): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;

        $this->info($since ? "Rebuilding concurrency since {$since->toIso8601String()}..." : 'Rebuilding concurrency (full history)...');

        $buckets = $scanner->rebuildMinuteConcurrency($since);

        $this->info("Wrote {$buckets} minute buckets.");

        return self::SUCCESS;
    }
}
