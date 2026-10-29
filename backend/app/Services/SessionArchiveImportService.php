<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Import a legacy CC Session Manager SQLite archive into sessions.* .
 *
 * Written for the cc-session-manager retirement: its
 * data/sessions.db is the only surviving copy of 2025-12-29 → 2026-02-10, and
 * none of those .jsonl files still exist on disk.
 *
 * PHP here has no pdo_sqlite, so rows are streamed through the `sqlite3` CLI in
 * keyset-paged chunks rather than read directly. Imported sessions are stamped
 * file_missing_at, since by definition their source files are gone — that flag
 * is what keeps SessionScannerService from treating them as orphans.
 *
 * Idempotent: re-running skips rows already present (ON CONFLICT DO NOTHING).
 */
class SessionArchiveImportService
{
    private const CHUNK = 500;

    /** @var array<string,string> Rows that could not be decoded, keyed by table:id */
    private array $skipped = [];

    /** @var array<string,true> session_ids seen by importSessions, for the message FK check */
    private array $seenSessionIds = [];

    public function __construct(private readonly string $sqlitePath)
    {
        if (!is_readable($this->sqlitePath)) {
            throw new RuntimeException("SQLite archive not readable: {$this->sqlitePath}");
        }
    }

    /**
     * @return array{projects: int, sessions: int, messages: int, skipped: array<string,string>}
     */
    public function import(bool $dryRun = false): array
    {
        $projectMap = $this->importProjects($dryRun);
        $sessions = $this->importSessions($projectMap, $dryRun);
        $messages = $this->importMessages($dryRun);
        $backfilled = $dryRun ? 0 : $this->backfillActivityTimestamps();

        return [
            'projects' => count($projectMap),
            'sessions' => $sessions,
            'messages' => $messages,
            'backfilled_timestamps' => $backfilled,
            'skipped' => $this->skipped,
        ];
    }

    /**
     * Counts in the archive, for pre-flight reporting.
     *
     * @return array{projects: int, sessions: int, messages: int, range: string}
     */
    public function summary(): array
    {
        $range = $this->query('SELECT min(first_prompt_timestamp) AS a, max(first_prompt_timestamp) AS b FROM sessions')[0] ?? [];

        return [
            'projects' => (int) ($this->query('SELECT count(*) AS c FROM projects')[0]['c'] ?? 0),
            'sessions' => (int) ($this->query('SELECT count(*) AS c FROM sessions')[0]['c'] ?? 0),
            'messages' => (int) ($this->query('SELECT count(*) AS c FROM messages')[0]['c'] ?? 0),
            'range' => trim(($range['a'] ?? '?') . ' → ' . ($range['b'] ?? '?')),
        ];
    }

    /**
     * Map archive project ids → sessions.projects ids, creating missing rows.
     *
     * @return array<int,int>
     */
    private function importProjects(bool $dryRun): array
    {
        $map = [];

        foreach ($this->query('SELECT * FROM projects ORDER BY id') as $row) {
            $existing = DB::table('sessions.projects')
                ->where('encoded_path', $row['encoded_path'])
                ->value('id');

            if ($existing) {
                $map[(int) $row['id']] = (int) $existing;
                continue;
            }

            if ($dryRun) {
                // Negative sentinel: this project WOULD be created, so sessions
                // pointing at it must still count as importable.
                $map[(int) $row['id']] = -1 * (int) $row['id'];
                continue;
            }

            $map[(int) $row['id']] = (int) DB::table('sessions.projects')->insertGetId([
                'encoded_path' => $row['encoded_path'],
                'decoded_path' => $row['decoded_path'],
                'display_name' => $row['display_name'],
                'is_pinned' => (bool) $row['is_pinned'],
                'is_archived' => (bool) $row['is_archived'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }

        return $map;
    }

    /** @param array<int,int> $projectMap */
    private function importSessions(array $projectMap, bool $dryRun): int
    {
        $imported = 0;
        $now = now();

        foreach ($this->paged('sessions') as $row) {
            $projectId = $projectMap[(int) $row['project_id']] ?? null;
            if ($projectId === null) {
                $this->skipped['sessions:' . $row['id']] = 'unmapped project_id ' . $row['project_id'];
                continue;
            }

            $this->seenSessionIds[$row['session_id']] = true;

            if ($dryRun) {
                $imported++;
                continue;
            }

            $affected = DB::table('sessions.sessions')->insertOrIgnore([
                'session_id' => $row['session_id'],
                'project_id' => $projectId,
                'file_path' => $row['file_path'],
                'file_size' => (int) $row['file_size'],
                'file_mtime' => (int) $row['file_mtime'],
                'first_prompt' => $this->clean($row['first_prompt']),
                'first_prompt_timestamp' => $row['first_prompt_timestamp'] ?: null,
                'last_activity_timestamp' => $row['last_activity_timestamp'] ?: null,
                'git_branch' => $row['git_branch'],
                'cwd' => $row['cwd'],
                'claude_version' => $row['claude_version'],
                'message_count' => (int) $row['message_count'],
                'turn_count' => (int) $row['turn_count'],
                'has_subagents' => (bool) $row['has_subagents'],
                'total_input_tokens' => (int) $row['total_input_tokens'],
                'total_output_tokens' => (int) $row['total_output_tokens'],
                'total_cache_creation_tokens' => (int) $row['total_cache_creation_tokens'],
                'total_cache_read_tokens' => (int) $row['total_cache_read_tokens'],
                'estimated_cost_usd' => round((float) $row['estimated_cost_usd'], 4),
                'fork_count' => (int) ($row['fork_count'] ?? 0),
                'is_starred' => (bool) $row['is_starred'],
                'is_archived' => (bool) $row['is_archived'],
                'notes' => $this->clean($row['notes']),
                'indexed_at' => $row['indexed_at'],
                // Source .jsonl no longer exists — this is what makes the
                // scanner leave these rows alone instead of orphaning them.
                'file_missing_at' => $now,
            ]);

            $imported += $affected;
        }

        return $imported;
    }

    private function importMessages(bool $dryRun): int
    {
        $imported = 0;
        $buffer = [];

        // Only import messages whose session actually landed, so the FK holds.
        // Union of what's in the DB and what importSessions just handled — the
        // latter is what makes --dry-run report a real message count.
        $knownSessions = $this->seenSessionIds + array_flip(
            DB::table('sessions.sessions')->pluck('session_id')->toArray()
        );

        foreach ($this->paged('messages') as $row) {
            if (!isset($knownSessions[$row['session_id']])) {
                continue;
            }

            $buffer[] = [
                'session_id' => $row['session_id'],
                'message_uuid' => $row['message_uuid'],
                'parent_uuid' => $row['parent_uuid'],
                'message_type' => $row['message_type'],
                'timestamp' => $row['timestamp'],
                'content_text' => $this->clean($row['content_text']),
                'model' => $row['model'],
                'input_tokens' => $this->intOrNull($row['input_tokens']),
                'output_tokens' => $this->intOrNull($row['output_tokens']),
                'cache_creation_tokens' => $this->intOrNull($row['cache_creation_tokens']),
                'cache_read_tokens' => $this->intOrNull($row['cache_read_tokens']),
                'tool_names' => $this->toolNames($row['tool_names']),
                'is_sidechain' => (bool) $row['is_sidechain'],
                'agent_id' => $row['agent_id'],
                'sequence_number' => (int) $row['sequence_number'],
            ];

            if (count($buffer) >= self::CHUNK) {
                $imported += $dryRun ? count($buffer) : DB::table('sessions.messages')->insertOrIgnore($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            $imported += $dryRun ? count($buffer) : DB::table('sessions.messages')->insertOrIgnore($buffer);
        }

        return $imported;
    }

    /**
     * Some archive rows carry no first_prompt/last_activity timestamp. Derive
     * them from their messages so the sessions stay sortable and dateable.
     */
    private function backfillActivityTimestamps(): int
    {
        return DB::update(<<<'SQL'
            UPDATE sessions.sessions s
               SET last_activity_timestamp = COALESCE(s.last_activity_timestamp, m.max_ts),
                   first_prompt_timestamp  = COALESCE(s.first_prompt_timestamp, m.min_ts)
              FROM (
                    SELECT session_id, min(timestamp) AS min_ts, max(timestamp) AS max_ts
                      FROM sessions.messages
                     GROUP BY session_id
                   ) m
             WHERE m.session_id = s.session_id
               AND s.file_missing_at IS NOT NULL
               AND (s.last_activity_timestamp IS NULL OR s.first_prompt_timestamp IS NULL)
        SQL);
    }

    /**
     * Keyset-paged read of a table (OFFSET would be O(n) over 88k rows).
     *
     * @return \Generator<array<string,mixed>>
     */
    private function paged(string $table): \Generator
    {
        $lastId = 0;

        while (true) {
            $sql = sprintf(
                'SELECT * FROM %s WHERE id > %d ORDER BY id LIMIT %d',
                $table,
                $lastId,
                self::CHUNK
            );

            $rows = $this->query($sql, $table);
            if (!$rows) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = max($lastId, (int) $row['id']);
                yield $row;
            }
        }
    }

    /**
     * Run a query through the sqlite3 CLI and decode its JSON output.
     *
     * On a decode failure (bad UTF-8 in one row) the chunk is retried row by
     * row so a single poisoned row costs one message, not 500.
     *
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sql, ?string $retryTable = null): array
    {
        $out = $this->runSqlite($sql);

        if ($out === '') {
            return [];
        }

        $decoded = json_decode($out, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if ($retryTable === null) {
            throw new RuntimeException("sqlite3 returned undecodable JSON for: {$sql}");
        }

        return $this->retryRowByRow($sql, $retryTable);
    }

    /** @return array<int,array<string,mixed>> */
    private function retryRowByRow(string $sql, string $table): array
    {
        preg_match('/id > (\d+) ORDER BY id LIMIT (\d+)/', $sql, $m);
        if (!$m) {
            throw new RuntimeException("Cannot retry undecodable chunk: {$sql}");
        }

        $ids = json_decode($this->runSqlite(
            sprintf('SELECT id FROM %s WHERE id > %d ORDER BY id LIMIT %d', $table, (int) $m[1], (int) $m[2])
        ), true) ?: [];

        $rows = [];
        foreach ($ids as $idRow) {
            $id = (int) $idRow['id'];
            $one = json_decode($this->runSqlite(sprintf('SELECT * FROM %s WHERE id = %d', $table, $id)), true);
            if (is_array($one) && $one) {
                $rows[] = $one[0];
            } else {
                $this->skipped["{$table}:{$id}"] = 'undecodable row (invalid UTF-8?)';
            }
        }

        return $rows;
    }

    private function runSqlite(string $sql): string
    {
        $process = new Process(['sqlite3', '-json', '-readonly', $this->sqlitePath, $sql]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('sqlite3 failed: ' . trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    /** Postgres text cannot hold NUL bytes; SQLite text can. */
    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace("\0", '', $value);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** SQLite stores tool_names comma-separated; Postgres wants text[]. */
    private function toolNames(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($n) => $n !== ''));
        if (!$names) {
            return null;
        }

        $escaped = array_map(
            fn (string $n) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $n) . '"',
            $names
        );

        return '{' . implode(',', $escaped) . '}';
    }
}
