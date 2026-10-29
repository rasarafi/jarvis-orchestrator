<?php

namespace App\Services;

use App\Enums\LiveSessionStatus;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads Claude Code transcript files (~/.claude/projects/*.jsonl) directly to
 * report sessions that are live right now. The Postgres session index is not
 * used here: it refreshes on a 2-hour schedule and has no notion of "active".
 *
 * Parsing is incremental: per-file byte offset + running totals are cached, so
 * a poll only reads bytes appended since the previous poll.
 */
class LiveSessionService
{
    /** Sessions idle longer than this are not "live". */
    private const WINDOW_MINUTES = 30;

    /** A file touched within this many seconds means Claude is actively working. */
    private const WORKING_THRESHOLD_SECONDS = 60;

    private const CACHE_TTL_MINUTES = 120;

    /** Price per 1M tokens: [input, output]. Cache write = 1.25x input, cache read = 0.1x input. */
    private const MODEL_PRICING = [
        'claude-fable-5' => [10.0, 50.0],
        'claude-mythos-5' => [10.0, 50.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-sonnet-5' => [3.0, 15.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-opus-4-5-20251101' => [5.0, 25.0],
        'claude-sonnet-4-5-20250929' => [3.0, 15.0],
        'claude-haiku-4-5-20251001' => [1.0, 5.0],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];

    public function __construct(private readonly SessionAttentionService $attention)
    {
    }

    public function live(): array
    {
        $projectsDir = config('services.claude.projects_dir');
        $cutoff = time() - self::WINDOW_MINUTES * 60;
        $sessions = [];

        foreach (glob($projectsDir . '/*/*.jsonl') ?: [] as $path) {
            clearstatcache(false, $path);
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime < $cutoff) {
                continue;
            }

            try {
                $state = $this->parseIncremental($path, $mtime);
            } catch (\Throwable $e) {
                Log::warning("live-sessions: failed to parse {$path}: {$e->getMessage()}");
                continue;
            }

            if ($state === null) {
                continue;
            }

            // mtime is only a cheap pre-filter: file copies/backups refresh it
            // without new content. The message timestamps are authoritative.
            $lastActivity = $state['last_activity_at'] ? strtotime($state['last_activity_at']) : $mtime;
            if ($lastActivity < $cutoff) {
                continue;
            }

            $sessions[] = $this->toPayload($path, $lastActivity, $state);
        }

        $this->attachProjectNames($sessions);
        $this->attachAttention($sessions);

        usort($sessions, function (array $a, array $b) {
            $rank = [
                LiveSessionStatus::WAITING->value => 0,
                LiveSessionStatus::WORKING->value => 1,
                LiveSessionStatus::STALLED->value => 2,
            ];

            return [$rank[$a['status']], $b['last_activity_at']] <=> [$rank[$b['status']], $a['last_activity_at']];
        });

        return [
            'data' => $sessions,
            'meta' => [
                'scanned_at' => now()->toIso8601String(),
                'window_minutes' => self::WINDOW_MINUTES,
            ],
        ];
    }

    /**
     * Parse only the bytes appended since the last poll, folding them into the
     * cached running state. Returns null for files with no message lines yet.
     */
    private function parseIncremental(string $path, int $mtime): ?array
    {
        $cacheKey = 'live_sess:' . md5($path);
        $size = filesize($path);

        $state = Cache::get($cacheKey);

        if ($state !== null && $state['offset'] === $size && $state['mtime'] === $mtime) {
            return $state['has_messages'] ? $state : null;
        }

        if ($state === null || $size < $state['offset']) {
            // First sight, or the file was truncated/rewritten: start over.
            $state = [
                'offset' => 0,
                'mtime' => $mtime,
                'has_messages' => false,
                'tokens' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0],
                'cost_usd' => 0.0,
                'started_at' => null,
                'last_activity_at' => null,
                'last_role' => null,
                'last_output_line' => null,
                'last_output_lines' => [],
                'model' => null,
                'cwd' => null,
                'git_branch' => null,
            ];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $state['offset']);
        $chunk = stream_get_contents($handle);
        fclose($handle);

        // Only consume complete lines; a partially-written trailing line is
        // re-read on the next poll.
        $consumed = strrpos($chunk, "\n");
        if ($consumed === false) {
            $state['mtime'] = $mtime;
            Cache::put($cacheKey, $state, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return $state['has_messages'] ? $state : null;
        }

        foreach (explode("\n", substr($chunk, 0, $consumed)) as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || !in_array($record['type'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }

            $state['has_messages'] = true;
            $state['started_at'] ??= $record['timestamp'] ?? null;
            $state['last_activity_at'] = $record['timestamp'] ?? $state['last_activity_at'];
            $state['cwd'] ??= $record['cwd'] ?? null;
            $state['git_branch'] = $record['gitBranch'] ?? $state['git_branch'];
            $state['last_role'] = $record['type'];

            if ($record['type'] !== 'assistant') {
                continue;
            }

            $message = $record['message'] ?? [];
            $state['model'] = $message['model'] ?? $state['model'];

            foreach ($message['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text' && trim($block['text'] ?? '') !== '') {
                    $state['last_output_line'] = mb_substr(
                        preg_replace('/\s+/', ' ', trim($block['text'])), 0, 200
                    );
                    $state['last_output_lines'][] = $state['last_output_line'];
                    $state['last_output_lines'] = array_slice($state['last_output_lines'], -5);
                }
            }

            $usage = $message['usage'] ?? null;
            if (is_array($usage)) {
                $state['tokens']['input'] += $usage['input_tokens'] ?? 0;
                $state['tokens']['output'] += $usage['output_tokens'] ?? 0;
                $state['tokens']['cache_read'] += $usage['cache_read_input_tokens'] ?? 0;
                $state['tokens']['cache_creation'] += $usage['cache_creation_input_tokens'] ?? 0;
                $state['cost_usd'] += $this->costOf($usage, $state['model']);
            }
        }

        $state['offset'] += $consumed + 1;
        $state['mtime'] = $mtime;
        Cache::put($cacheKey, $state, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $state['has_messages'] ? $state : null;
    }

    private function costOf(array $usage, ?string $model): float
    {
        [$in, $out] = self::MODEL_PRICING[$model] ?? [5.0, 25.0];

        return (($usage['input_tokens'] ?? 0) * $in
            + ($usage['output_tokens'] ?? 0) * $out
            + ($usage['cache_creation_input_tokens'] ?? 0) * $in * 1.25
            + ($usage['cache_read_input_tokens'] ?? 0) * $in * 0.10
        ) / 1_000_000;
    }

    private function toPayload(string $path, int $lastActivity, array $state): array
    {
        $idleSeconds = time() - $lastActivity;

        $status = match (true) {
            $idleSeconds <= self::WORKING_THRESHOLD_SECONDS => LiveSessionStatus::WORKING,
            $state['last_role'] === 'assistant' => LiveSessionStatus::WAITING,
            default => LiveSessionStatus::STALLED,
        };

        $startedAt = $state['started_at'] ? strtotime($state['started_at']) : null;

        return [
            'session_id' => basename($path, '.jsonl'),
            'project_dir' => basename(dirname($path)),
            'project_name' => null, // filled by attachProjectNames()
            'project_slug' => null, // filled by attachProjectNames()
            'cwd' => $state['cwd'],
            'git_branch' => $state['git_branch'],
            'status' => $status->value,
            'started_at' => $state['started_at'],
            'last_activity_at' => $state['last_activity_at'],
            'elapsed_seconds' => $startedAt ? max(0, time() - $startedAt) : null,
            'tokens' => $state['tokens'],
            'cost_usd' => round($state['cost_usd'], 4),
            'last_output_line' => $state['last_output_line'],
            'last_output_lines' => $state['last_output_lines'] ?? [],
            'model' => $state['model'],
            'attention' => null, // filled by attachAttention()
        ];
    }

    /**
     * Longest-prefix match of session cwd against projects.local_path, with a
     * cwd-basename fallback for repos Jarvis doesn't know about.
     */
    private function attachProjectNames(array &$sessions): void
    {
        if ($sessions === []) {
            return;
        }

        $projects = Project::query()
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->get(['name', 'slug', 'local_path'])
            ->sortByDesc(fn (Project $p) => strlen($p->local_path))
            ->values();

        foreach ($sessions as &$session) {
            $cwd = $session['cwd'] ?? '';
            foreach ($projects as $project) {
                if ($cwd === $project->local_path || str_starts_with($cwd, rtrim($project->local_path, '/') . '/')) {
                    $session['project_name'] = $project->name;
                    $session['project_slug'] = $project->slug;
                    break;
                }
            }
            $session['project_name'] ??= $cwd !== '' ? basename($cwd) : $session['project_dir'];
        }
    }

    /** Fresh activity within this many seconds of the flag counts as "human responded". */
    private const ATTENTION_GRACE_SECONDS = 10;

    /**
     * Merge hook-pushed needs-attention flags into the payload. A flag is
     * dropped (rather than merged) when either:
     *  - the session is WORKING again (fresh activity — the human responded), or
     *  - last_activity_at is newer than flagged_at + grace, even if the poll
     *    didn't catch it mid-WORKING (e.g. it settled back to waiting/stalled
     *    between polls).
     *
     * Afterwards, prune any flag whose session is no longer live at all and
     * has aged past the live window — it belongs to a session that vanished
     * without ever getting a fresh-activity signal.
     */
    private function attachAttention(array &$sessions): void
    {
        $flags = $this->attention->all();

        if ($flags !== []) {
            foreach ($sessions as &$session) {
                $flag = $flags[$session['session_id']] ?? null;
                if ($flag === null) {
                    continue;
                }

                $freshActivity = $session['last_activity_at'] !== null
                    && isset($flag['flagged_at'])
                    && Carbon::parse($session['last_activity_at'])
                        ->gt(Carbon::parse($flag['flagged_at'])->addSeconds(self::ATTENTION_GRACE_SECONDS));

                if ($freshActivity || $session['status'] === LiveSessionStatus::WORKING->value) {
                    $this->attention->clear($session['session_id']);
                    continue;
                }

                $session['attention'] = [
                    'kind' => $flag['kind'],
                    'message' => $flag['message'],
                    'flagged_at' => $flag['flagged_at'],
                ];
            }
            unset($session);
        }

        $this->attention->pruneAbsent(array_column($sessions, 'session_id'), self::WINDOW_MINUTES);
    }
}
