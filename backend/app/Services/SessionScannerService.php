<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SessionScannerService
{
    private string $projectsDir;

    /**
     * Base model rates in USD per 1M tokens: [input, output].
     *
     * Cache rates are NOT stored — they are fixed multiples of the input rate
     * (see the CACHE_* constants), so they cannot drift out of sync with it.
     */
    public const MODEL_RATES = [
        'claude-fable-5'             => [10.0, 50.0],
        'claude-mythos-5'            => [10.0, 50.0],
        'claude-opus-5'              => [5.0, 25.0],
        'claude-opus-4-8'            => [5.0, 25.0],
        'claude-opus-4-7'            => [5.0, 25.0],
        'claude-opus-4-6'            => [5.0, 25.0],
        'claude-opus-4-5-20251101'   => [5.0, 25.0],
        'claude-sonnet-5'            => [3.0, 15.0],
        'claude-sonnet-4-6'          => [3.0, 15.0],
        'claude-sonnet-4-5-20250929' => [3.0, 15.0],
        'claude-haiku-4-5'           => [1.0, 5.0],
        'claude-haiku-4-5-20251001'  => [1.0, 5.0],
    ];

    /** @return array<string, array{0: float, 1: float}> model => [input rate, output rate] */
    public static function modelRates(): array
    {
        return self::MODEL_RATES;
    }

    /** Writing to the 5-minute-TTL prompt cache costs 1.25x the base input rate. */
    public const CACHE_WRITE_5M_MULTIPLIER = 1.25;

    /** Writing to the 1-hour-TTL prompt cache costs 2x the base input rate. */
    public const CACHE_WRITE_1H_MULTIPLIER = 2.0;

    /** Reading from the prompt cache costs 0.1x the base input rate. */
    public const CACHE_READ_MULTIPLIER = 0.1;

    /**
     * Models seen in the .jsonl with no MODEL_RATES entry, collected per scan so
     * an unpriced model surfaces as a warning instead of silently costing $0.
     *
     * @var array<string, int>
     */
    private array $unpricedModels = [];

    /** session_id => account, loaded once per scan. @var array<string, string>|null */
    private ?array $accountMap = null;

    public function __construct()
    {
        $this->projectsDir = env('CLAUDE_PROJECTS_DIR', getenv('HOME') . '/.claude/projects');
    }

    /**
     * Full scan of all project directories.
     *
     * @return array{added: int, updated: int, skipped: int, removed: int}
     */
    public function scanAll(): array
    {
        $this->accountMap = null;
        $scanId = $this->startScanHistory();
        $totals = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'removed' => 0];

        try {
            $projectDirs = $this->discoverProjects();
            $allFilePaths = [];

            foreach ($projectDirs as $dirName) {
                $dirPath = $this->projectsDir . '/' . $dirName;
                $result = $this->scanProjectDir($dirName, $dirPath);
                $totals['added'] += $result['added'];
                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];

                // Collect all current file paths for orphan detection
                foreach ($this->discoverSessions($dirPath) as $file) {
                    $allFilePaths[] = $file;
                }
            }

            // Clean up orphaned sessions (files that no longer exist)
            $totals['removed'] = $this->removeOrphanedSessions($allFilePaths);

            $this->warnOnUnpricedModels();
            $this->rebuildMinuteConcurrency(now()->subDays(2));
            $this->completeScanHistory($scanId, 'completed', $totals);
        } catch (\Throwable $e) {
            $this->completeScanHistory($scanId, 'failed', $totals, $e->getMessage());
            throw $e;
        }

        return $totals;
    }

    /**
     * Scan a specific project directory only.
     *
     * @param string $projectDir The directory name (e.g., '-home-dev-projects-foo')
     * @return array{added: int, updated: int, skipped: int, removed: int}
     */
    public function scanProject(string $projectDir): array
    {
        $this->accountMap = null;
        $dirPath = $this->projectsDir . '/' . $projectDir;

        if (!is_dir($dirPath)) {
            throw new \RuntimeException("Project directory not found: {$dirPath}");
        }

        $result = $this->scanProjectDir($projectDir, $dirPath);

        // For project-specific scan, remove orphans only for this project
        $currentFiles = $this->discoverSessions($dirPath);
        $projectId = $this->ensureProject($projectDir);
        $result['removed'] = $this->removeOrphanedSessionsForProject($projectId, $currentFiles);

        $this->rebuildMinuteConcurrency(now()->subDays(2));

        return $result;
    }

    /**
     * Scan all sessions in a single project directory.
     */
    private function scanProjectDir(string $dirName, string $dirPath): array
    {
        $result = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        if (!is_dir($dirPath)) {
            return $result;
        }

        $projectId = $this->ensureProject($dirName);
        $sessionFiles = $this->discoverSessions($dirPath);

        foreach ($sessionFiles as $filePath) {
            try {
                $sessionInfo = $this->parseSessionFile($filePath);
                if (empty($sessionInfo['metadata']['session_id'])) {
                    continue; // No valid session data found
                }

                $outcome = $this->indexSession($sessionInfo, $projectId);
                $result[$outcome]++;
            } catch (\Throwable $e) {
                Log::warning("SessionScanner: Failed to parse {$filePath}: {$e->getMessage()}");
            }
        }

        $this->syncSubagentFlags($projectId);

        return $result;
    }

    /**
     * Flag main sessions that spawned subagents.
     *
     * Derived from the indexed children rather than from the parent transcript:
     * the parent .jsonl carries no `agentId`, so parsing alone never sets it.
     */
    private function syncSubagentFlags(int $projectId): void
    {
        DB::statement("
            UPDATE sessions.sessions p
               SET has_subagents = true
             WHERE p.project_id = ?
               AND p.kind = 'main'
               AND NOT p.has_subagents
               AND EXISTS (
                   SELECT 1 FROM sessions.sessions c
                    WHERE c.parent_session_id = p.session_id
               )
        ", [$projectId]);
    }

    /**
     * Which Claude account a session belongs to, from the SessionStart hook's
     * mapping. Null for anything started before the hook existed — the .jsonl
     * carries no account signal, so it cannot be recovered later.
     */
    private function accountFor(?string $sessionId): ?string
    {
        if ($sessionId === null) {
            return null;
        }

        if ($this->accountMap === null) {
            $this->accountMap = DB::table('sessions.session_accounts')
                ->pluck('account', 'session_id')
                ->all();
        }

        return $this->accountMap[$sessionId] ?? null;
    }

    /**
     * List project directories in the Claude projects folder.
     *
     * @return string[] Directory names
     */
    private function discoverProjects(): array
    {
        if (!is_dir($this->projectsDir)) {
            return [];
        }

        $dirs = [];
        $handle = opendir($this->projectsDir);
        if ($handle === false) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($this->projectsDir . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }
        closedir($handle);

        sort($dirs);
        return $dirs;
    }

    /**
     * List .jsonl session files in a project directory.
     *
     * @return string[] Absolute file paths
     */
    private function discoverSessions(string $projectDirPath): array
    {
        if (!is_dir($projectDirPath)) {
            return [];
        }

        $files = [];
        $handle = opendir($projectDirPath);
        if ($handle === false) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            if (str_ends_with($entry, '.jsonl')) {
                $files[] = $projectDirPath . '/' . $entry;
                continue;
            }

            // Subagent transcripts live one level down, in
            // `<session-uuid>/subagents/agent-*.jsonl`. They are real, separate
            // token spend — never a duplicate of the parent file's contents —
            // so they must be indexed too, as their own rows.
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $subDir = $projectDirPath . '/' . $entry . '/subagents';
            if (is_dir($subDir)) {
                foreach ((glob($subDir . '/*.jsonl') ?: []) as $agentFile) {
                    $files[] = $agentFile;
                }
            }
        }
        closedir($handle);

        sort($files);
        return $files;
    }

    /**
     * Resolve the row identity for a transcript file.
     *
     * Subagent records carry the PARENT session's `sessionId`, so indexing them
     * under that id would overwrite the parent row. They get a synthetic key
     * instead: `<parent>:agent:<agentId>`.
     *
     * @return array{session_id: string|null, kind: string, parent_session_id: string|null}
     */
    private function sessionIdentity(string $filePath, ?string $rawSessionId): array
    {
        if (!str_contains($filePath, '/subagents/')) {
            return ['session_id' => $rawSessionId, 'kind' => 'main', 'parent_session_id' => null];
        }

        // .../<parent-uuid>/subagents/agent-<id>.jsonl — keyed by the AGENT id,
        // not parent+agent: a forked session inherits a COPY of the parent's
        // subagents/ directory, so the same agent run exists under two session
        // uuids. Keying on the pair would index one agent's spend twice.
        // parent_session_id comes from the directory, which is authoritative —
        // a duplicated file's records carry BOTH session ids.
        return [
            'session_id' => preg_replace('/\.jsonl$/', '', basename($filePath)),
            'kind' => 'subagent',
            'parent_session_id' => basename(dirname($filePath, 2)),
        ];
    }

    /**
     * Parse a JSONL session file line-by-line (memory-efficient).
     *
     * @return array{metadata: array, messages: array}
     */
    private function parseSessionFile(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $metadata = [
            'session_id' => null,
            'file_path' => $filePath,
            'file_size' => filesize($filePath),
            'file_mtime' => (int) (filemtime($filePath) * 1000),
            'first_prompt' => null,
            'first_prompt_timestamp' => null,
            'last_activity_timestamp' => null,
            'git_branch' => null,
            'cwd' => null,
            'claude_version' => null,
            'message_count' => 0,
            'turn_count' => 0,
            'kind' => 'main',
            'parent_session_id' => null,
            'subagent_type' => null,
            'spawn_depth' => null,
            'entrypoint' => null,
            'has_subagents' => false,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_cache_creation_tokens' => 0,
            'total_cache_creation_1h_tokens' => 0,
            'total_cache_read_tokens' => 0,
            'estimated_cost_usd' => 0.0,
            'fork_count' => 0,
        ];

        $messages = [];
        $lastRole = null;
        $sequenceNumber = 0;
        $parentChildMap = []; // parentUuid => [timestamps]

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);
            if ($record === null) {
                continue; // Skip malformed JSON lines
            }

            $type = $record['type'] ?? null;
            if ($type !== 'user' && $type !== 'assistant') {
                continue;
            }

            $uuid = $record['uuid'] ?? null;
            $parentUuid = $record['parentUuid'] ?? null;
            $timestamp = $record['timestamp'] ?? null;
            $isSidechain = $record['isSidechain'] ?? false;
            $sessionId = $record['sessionId'] ?? null;
            $message = $record['message'] ?? [];
            $sourceToolAssistantUUID = $record['sourceToolAssistantUUID'] ?? null;
            $agentId = $record['agentId'] ?? null;

            // Set session ID from first record that has it
            if ($sessionId && !$metadata['session_id']) {
                $metadata['session_id'] = $sessionId;
            }

            // 'cli' = interactive terminal; 'sdk-cli' | 'sdk-py' = a headless
            // `claude -p` / SDK invocation (verified against ClaudeCliService's
            // real recap/sweep transcripts). Constant for the whole session, so
            // capture it once from whichever record carries it first.
            if ($metadata['entrypoint'] === null && isset($record['entrypoint']) && is_string($record['entrypoint'])) {
                $metadata['entrypoint'] = $record['entrypoint'];
            }

            // Track subagents
            if ($agentId) {
                $metadata['has_subagents'] = true;
            }

            // Build parent-child map for fork detection (skip sidechains)
            if ($parentUuid && !$isSidechain && $timestamp) {
                $parentChildMap[$parentUuid][] = $timestamp;
            }

            // Update last activity timestamp
            if ($timestamp) {
                if (!$metadata['last_activity_timestamp'] || $timestamp > $metadata['last_activity_timestamp']) {
                    $metadata['last_activity_timestamp'] = $timestamp;
                }
            }

            if ($type === 'user') {
                $metadata['message_count']++;

                // Turn counting: increment when (lastRole == 'assistant' || lastRole == null) AND sourceToolAssistantUUID is NOT set
                if (($lastRole === 'assistant' || $lastRole === null) && !$sourceToolAssistantUUID) {
                    $metadata['turn_count']++;
                    $lastRole = 'user';
                }

                // Extract first prompt (first real user message, not tool result)
                if (!$metadata['first_prompt'] && !$sourceToolAssistantUUID) {
                    $contentText = $this->extractContentText($message['content'] ?? '');
                    if ($contentText !== '') {
                        $metadata['first_prompt'] = $this->truncateWordBoundary($contentText, 500);
                        $metadata['first_prompt_timestamp'] = $timestamp;
                    }
                }

                // Extract cwd, git_branch, version from first real user message
                if (!$metadata['cwd'] && !$sourceToolAssistantUUID) {
                    $metadata['cwd'] = $record['cwd'] ?? null;
                    $metadata['git_branch'] = $record['gitBranch'] ?? null;
                    $metadata['claude_version'] = $record['version'] ?? null;
                }

                // Build message record
                $sequenceNumber++;
                $messages[] = [
                    'message_uuid' => $uuid,
                    'parent_uuid' => $parentUuid,
                    'message_type' => 'user',
                    'timestamp' => $timestamp,
                    'content_text' => $this->truncateWordBoundary(
                        $this->extractContentText($message['content'] ?? ''),
                        2000
                    ),
                    'model' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'cache_creation_tokens' => null,
                    'cache_creation_1h_tokens' => null,
                    'cache_read_tokens' => null,
                    'tool_names' => null,
                    'skill_name' => null,
                    'is_sidechain' => $isSidechain,
                    'agent_id' => $agentId,
                    'sequence_number' => $sequenceNumber,
                ];
            } elseif ($type === 'assistant') {
                $metadata['message_count']++;
                $lastRole = 'assistant';

                $model = $message['model'] ?? null;
                $usage = $message['usage'] ?? [];
                $content = $message['content'] ?? [];

                $inputTokens = $usage['input_tokens'] ?? 0;
                $outputTokens = $usage['output_tokens'] ?? 0;
                $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
                $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;
                // The TTL split lives in a nested object; older lines omit it,
                // in which case the whole write is treated as 5-minute.
                $cacheCreation1hTokens = $usage['cache_creation']['ephemeral_1h_input_tokens'] ?? 0;

                // Accumulate totals
                $metadata['total_input_tokens'] += $inputTokens;
                $metadata['total_output_tokens'] += $outputTokens;
                $metadata['total_cache_creation_tokens'] += $cacheCreationTokens;
                $metadata['total_cache_creation_1h_tokens'] += $cacheCreation1hTokens;
                $metadata['total_cache_read_tokens'] += $cacheReadTokens;

                // Calculate cost for this message
                $cost = $this->calculateCost($model ?? '', $inputTokens, $outputTokens, $cacheCreationTokens, $cacheReadTokens, $cacheCreation1hTokens);
                $metadata['estimated_cost_usd'] += $cost;

                // Extract text and tools from content
                $contentText = '';
                $toolNames = [];
                $skillName = null;
                if (is_array($content)) {
                    $contentText = $this->extractContentText($content);
                    $toolNames = $this->extractToolNames($content);
                    $skillName = $this->extractSkillName($content);
                } elseif (is_string($content)) {
                    $contentText = $this->stripXmlTags($content);
                }

                $sequenceNumber++;
                $messages[] = [
                    'message_uuid' => $uuid,
                    'parent_uuid' => $parentUuid,
                    'message_type' => 'assistant',
                    'timestamp' => $timestamp,
                    'content_text' => $this->truncateWordBoundary($contentText, 2000),
                    'model' => $model,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cache_creation_tokens' => $cacheCreationTokens,
                    'cache_creation_1h_tokens' => $cacheCreation1hTokens,
                    'cache_read_tokens' => $cacheReadTokens,
                    'tool_names' => !empty($toolNames) ? $toolNames : null,
                    'skill_name' => $skillName,
                    'is_sidechain' => $isSidechain,
                    'agent_id' => $agentId,
                    'sequence_number' => $sequenceNumber,
                ];
            }
        }

        fclose($handle);

        // Calculate fork count
        $metadata['fork_count'] = $this->countForks($parentChildMap);

        // A subagent file's records carry the parent's sessionId — rewrite the
        // key so the two never collide. Must happen after parsing, because the
        // raw id is only known once a record has been read.
        $identity = $this->sessionIdentity($filePath, $metadata['session_id']);
        $metadata['session_id'] = $identity['session_id'];
        $metadata['kind'] = $identity['kind'];
        $metadata['parent_session_id'] = $identity['parent_session_id'];

        // A subagent transcript carries its own agentId on every record, which
        // would otherwise flag the leaf as having spawned subagents. The flag
        // belongs to parents only, and is set from the children in
        // syncSubagentFlags().
        if ($metadata['kind'] === 'subagent') {
            $metadata['has_subagents'] = false;

            $sidecar = $this->readSubagentSidecar($filePath);
            $metadata['subagent_type'] = $sidecar['subagent_type'];
            $metadata['spawn_depth'] = $sidecar['spawn_depth'];
        }

        return [
            'metadata' => $metadata,
            'messages' => $messages,
        ];
    }

    /**
     * Upsert session + messages into the database.
     *
     * @return string 'added'|'updated'|'skipped'
     */
    private function indexSession(array $sessionInfo, int $projectId): string
    {
        $meta = $sessionInfo['metadata'];
        $messages = $sessionInfo['messages'];
        $sessionId = $meta['session_id'];

        // Check for existing session
        $existing = DB::table('sessions.sessions')
            ->where('session_id', $sessionId)
            ->select('id', 'file_mtime', 'file_missing_at')
            ->first();

        // Skip if file hasn't changed — unless the row is flagged missing and the
        // file is back, in which case we reindex to clear the flag.
        if ($existing
            && (int) $existing->file_mtime === $meta['file_mtime']
            && $existing->file_missing_at === null) {
            return 'skipped';
        }

        $sessionData = [
            'session_id' => $sessionId,
            'project_id' => $projectId,
            'file_path' => $meta['file_path'],
            'file_size' => $meta['file_size'],
            'file_mtime' => $meta['file_mtime'],
            'first_prompt' => $meta['first_prompt'],
            'first_prompt_timestamp' => $meta['first_prompt_timestamp'],
            'last_activity_timestamp' => $meta['last_activity_timestamp'],
            'git_branch' => $meta['git_branch'],
            'cwd' => $meta['cwd'],
            'claude_version' => $meta['claude_version'],
            'message_count' => $meta['message_count'],
            'turn_count' => $meta['turn_count'],
            'kind' => $meta['kind'],
            'parent_session_id' => $meta['parent_session_id'],
            'subagent_type' => $meta['subagent_type'],
            'spawn_depth' => $meta['spawn_depth'],
            'entrypoint' => $meta['entrypoint'],
            'account' => $this->accountFor($meta['parent_session_id'] ?? $sessionId),
            'has_subagents' => $meta['has_subagents'],
            'total_input_tokens' => $meta['total_input_tokens'],
            'total_output_tokens' => $meta['total_output_tokens'],
            'total_cache_creation_tokens' => $meta['total_cache_creation_tokens'],
            'total_cache_creation_1h_tokens' => $meta['total_cache_creation_1h_tokens'],
            'total_cache_read_tokens' => $meta['total_cache_read_tokens'],
            'estimated_cost_usd' => round($meta['estimated_cost_usd'], 4),
            'fork_count' => $meta['fork_count'],
            'indexed_at' => now(),
            // The file exists right now — clear any previous missing flag.
            'file_missing_at' => null,
        ];

        DB::transaction(function () use ($existing, $sessionId, $sessionData, $messages) {
            if ($existing) {
                // Update: delete old messages, update session
                DB::table('sessions.messages')->where('session_id', $sessionId)->delete();
                DB::table('sessions.sessions')->where('session_id', $sessionId)->update($sessionData);
            } else {
                // Insert new session
                DB::table('sessions.sessions')->insert($sessionData);
            }

            // Bulk insert messages in chunks
            $this->insertMessages($sessionId, $messages);
        });

        return $existing ? 'updated' : 'added';
    }

    /**
     * Insert messages in bulk, chunked for large sessions.
     */
    private function insertMessages(string $sessionId, array $messages): void
    {
        $chunks = array_chunk($messages, 500);

        foreach ($chunks as $chunk) {
            $rows = [];
            foreach ($chunk as $msg) {
                $rows[] = [
                    'session_id' => $sessionId,
                    'message_uuid' => $msg['message_uuid'],
                    'parent_uuid' => $msg['parent_uuid'],
                    'message_type' => $msg['message_type'],
                    'timestamp' => $msg['timestamp'],
                    'content_text' => $msg['content_text'],
                    'model' => $msg['model'],
                    'input_tokens' => $msg['input_tokens'],
                    'output_tokens' => $msg['output_tokens'],
                    'cache_creation_tokens' => $msg['cache_creation_tokens'],
                    'cache_creation_1h_tokens' => $msg['cache_creation_1h_tokens'],
                    'cache_read_tokens' => $msg['cache_read_tokens'],
                    'tool_names' => $msg['tool_names'] ? $this->formatPgArray($msg['tool_names']) : null,
                    'skill_name' => $msg['skill_name'],
                    'is_sidechain' => $msg['is_sidechain'],
                    'agent_id' => $msg['agent_id'],
                    'sequence_number' => $msg['sequence_number'],
                ];
            }

            // insertOrIgnore, not insert: a forked or retried transcript repeats
            // the prefix it branched from, so the same message_uuid can appear in
            // two files (observed between two sibling subagent transcripts sharing
            // 50 records). A plain insert aborts the whole session's transaction on
            // that collision, dropping every message of an otherwise fine session —
            // 546 sessions were failing this way. The duplicate is one message
            // counted once; the session's own totals come from parsing, not here.
            DB::table('sessions.messages')->insertOrIgnore($rows);
        }
    }

    /**
     * Get or create a project in sessions.projects, return its ID.
     */
    private function ensureProject(string $dirName): int
    {
        $existing = DB::table('sessions.projects')
            ->where('encoded_path', $dirName)
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $decodedPath = $this->decodePath($dirName);

        $displayName = basename($decodedPath);

        return DB::table('sessions.projects')->insertGetId([
            'encoded_path' => $dirName,
            'decoded_path' => $decodedPath,
            'display_name' => $displayName,
            'is_pinned' => false,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Decode an encoded project directory name back to a filesystem path.
     *
     * Claude Code encodes paths by replacing '/', '_', and '.' with '-'. We
     * resolve ambiguity by checking what exists on the filesystem, using
     * recursive backtracking so that a greedy match (e.g. "portfolio" as a
     * directory) doesn't prevent finding the correct deeper path
     * (e.g. "portfolio-app" as a single directory name).
     */
    private function decodePath(string $encoded): string
    {
        $raw = ltrim($encoded, '-');
        $segments = explode('-', $raw);

        $result = $this->resolveSegments($segments, '/');

        return $result ?? ('/' . str_replace('-', '/', $raw));
    }

    /**
     * Recursively resolve segments against the filesystem with backtracking.
     *
     * Returns the resolved path if it leads to an existing directory, or null
     * if no valid resolution exists from this point.
     */
    private function resolveSegments(array $segments, string $basePath): ?string
    {
        if (empty($segments)) {
            return $basePath;
        }

        $base = rtrim($basePath, '/');

        // Try consuming multiple segments as one name (hyphenated or underscored),
        // starting from longest match down to single segment.
        // Longest-first ensures "portfolio-app" is tried before "portfolio" + "app".
        for ($len = count($segments); $len >= 1; $len--) {
            $parts = array_slice($segments, 0, $len);
            $remaining = array_slice($segments, $len);

            // Build candidate names: hyphenated, underscored, single segment (same thing for len=1)
            $candidates = [implode('-', $parts)];
            if ($len > 1) {
                $candidates[] = implode('_', $parts);
            }

            foreach ($candidates as $candidate) {
                $candidatePath = $base . '/' . $candidate;

                if (is_dir($candidatePath)) {
                    if (empty($remaining)) {
                        return $candidatePath;
                    }

                    // Recurse with remaining segments
                    $result = $this->resolveSegments($remaining, $candidatePath);
                    if ($result !== null) {
                        return $result;
                    }
                    // Backtrack — this directory match leads to a dead end
                }
            }
        }

        // No valid path found from here — allow leaf (last segments with no dir)
        // This handles the final component which may not be a directory
        $leafCandidate = $base . '/' . implode('-', $segments);
        return $leafCandidate;
    }

    /**
     * Calculate cost in USD for a single message.
     *
     * $cacheCreate is the TOTAL cache-write tokens; $cacheCreate1h is the
     * portion of that total written at 1-hour TTL. The remainder is 5-minute.
     */
    private function calculateCost(
        string $model,
        int $input,
        int $output,
        int $cacheCreate,
        int $cacheRead,
        int $cacheCreate1h = 0
    ): float {
        $rates = self::MODEL_RATES[$model] ?? null;
        if (!$rates) {
            if ($model !== '' && $model !== '<synthetic>') {
                $this->unpricedModels[$model] = ($this->unpricedModels[$model] ?? 0) + 1;
            }
            return 0.0;
        }

        [$inputRate, $outputRate] = $rates;

        // Guard against a malformed 1h figure exceeding the total it is part of.
        $cacheCreate1h = max(0, min($cacheCreate1h, $cacheCreate));
        $cacheCreate5m = $cacheCreate - $cacheCreate1h;

        return ($input / 1_000_000) * $inputRate
             + ($output / 1_000_000) * $outputRate
             + ($cacheCreate5m / 1_000_000) * $inputRate * self::CACHE_WRITE_5M_MULTIPLIER
             + ($cacheCreate1h / 1_000_000) * $inputRate * self::CACHE_WRITE_1H_MULTIPLIER
             + ($cacheRead / 1_000_000) * $inputRate * self::CACHE_READ_MULTIPLIER;
    }

    /**
     * Log any model seen without a price entry. A new model ID ships and every
     * message on it costs $0 until MODEL_RATES learns about it — this is the
     * only thing that makes that visible.
     */
    private function warnOnUnpricedModels(): void
    {
        foreach ($this->unpricedModels as $model => $count) {
            Log::warning("SessionScanner: no price for model '{$model}' — {$count} message(s) costed at \$0. Add it to MODEL_RATES.");
        }
    }

    /**
     * Models encountered during this instance's scans that have no price entry.
     *
     * @return array<string, int> model => message count
     */
    public function unpricedModels(): array
    {
        return $this->unpricedModels;
    }

    /**
     * Precompute per-minute concurrency (minute bucket -> distinct active
     * session count) from `sessions.messages`, so `high_parallel` can read it
     * off the table instead of an interval-overlap join over the full corpus.
     *
     * $since bounds the rebuild for cheap scan-time maintenance (default calls
     * use the last 2 days); pass null for a full-history rebuild — the
     * regenerate-from-messages-at-any-time escape hatch
     * (`jarvis:rebuild-concurrency`).
     */
    public function rebuildMinuteConcurrency(?Carbon $since = null): int
    {
        $query = DB::table('sessions.messages');
        if ($since !== null) {
            $query->where('timestamp', '>=', $since);
        }

        $rows = $query
            ->selectRaw("date_trunc('minute', timestamp) as minute_bucket, count(distinct session_id) as active_sessions")
            ->groupBy('minute_bucket')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $values = $rows->map(fn ($r) => [
            'minute_bucket' => $r->minute_bucket,
            'active_sessions' => (int) $r->active_sessions,
        ])->all();

        foreach (array_chunk($values, 1000) as $chunk) {
            DB::table('sessions.minute_concurrency')->upsert($chunk, ['minute_bucket'], ['active_sessions']);
        }

        return count($values);
    }

    /**
     * One-shot backfill for A1 attribution that a normal reindex will never
     * pick up: `indexSession()` skips a file whose mtime hasn't changed, but
     * these sidecars/fields existed on disk all along — they just weren't read
     * yet. Re-derives them directly from the files already on disk, without a
     * full reparse.
     *
     * @return array{subagent_type_updated: int, subagent_type_missing_sidecar: int, entrypoint_updated: int, entrypoint_not_found: int}
     */
    public function backfillAttribution(): array
    {
        $result = [
            'subagent_type_updated' => 0,
            'subagent_type_missing_sidecar' => 0,
            'entrypoint_updated' => 0,
            'entrypoint_not_found' => 0,
        ];

        $subagents = DB::table('sessions.sessions')
            ->where('kind', 'subagent')
            ->whereNull('subagent_type')
            ->select('id', 'file_path')
            ->get();

        foreach ($subagents as $row) {
            $sidecar = $this->readSubagentSidecar($row->file_path);
            if ($sidecar['subagent_type'] === null && $sidecar['spawn_depth'] === null) {
                $result['subagent_type_missing_sidecar']++;
                continue;
            }

            DB::table('sessions.sessions')->where('id', $row->id)->update([
                'subagent_type' => $sidecar['subagent_type'],
                'spawn_depth' => $sidecar['spawn_depth'],
            ]);
            $result['subagent_type_updated']++;
        }

        $missingEntrypoint = DB::table('sessions.sessions')
            ->whereNull('entrypoint')
            ->select('id', 'file_path')
            ->get();

        foreach ($missingEntrypoint as $row) {
            $entrypoint = $this->readEntrypointBounded($row->file_path);
            if ($entrypoint === null) {
                $result['entrypoint_not_found']++;
                continue;
            }

            DB::table('sessions.sessions')->where('id', $row->id)->update(['entrypoint' => $entrypoint]);
            $result['entrypoint_updated']++;
        }

        return $result;
    }

    /**
     * Bounded read of a transcript's `entrypoint`, for the backfill only — a
     * full `parseSessionFile()` would work too but is wasteful when every
     * transcript observed so far carries it within the first few lines.
     */
    private function readEntrypointBounded(string $filePath, int $maxLines = 30): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return null;
        }

        $entrypoint = null;
        $linesRead = 0;
        while ($linesRead < $maxLines && ($line = fgets($handle)) !== false) {
            $linesRead++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (is_array($record) && isset($record['entrypoint']) && is_string($record['entrypoint'])) {
                $entrypoint = $record['entrypoint'];
                break;
            }
        }
        fclose($handle);

        return $entrypoint;
    }

    /**
     * Extract readable text from message content (string or array of blocks).
     */
    private function extractContentText(mixed $content): string
    {
        if (is_string($content)) {
            return $this->stripXmlTags($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                if (is_string($block)) {
                    $parts[] = $block;
                }
                continue;
            }

            $blockType = $block['type'] ?? null;

            if ($blockType === 'text') {
                $parts[] = $block['text'] ?? '';
            } elseif ($blockType === 'thinking') {
                $parts[] = $block['thinking'] ?? '';
            } elseif ($blockType === 'tool_result') {
                // Recursively extract from tool_result content
                $resultContent = $block['content'] ?? '';
                $parts[] = $this->extractContentText($resultContent);
            }
            // Skip tool_use blocks — not searchable text
        }

        return $this->stripXmlTags(implode("\n", array_filter($parts)));
    }

    /**
     * Extract tool names from assistant content array.
     *
     * @return string[]
     */
    private function extractToolNames(array $content): array
    {
        $tools = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $name = $block['name'] ?? null;
                if ($name) {
                    $tools[] = $name;
                }
            }
        }
        return $tools;
    }

    /**
     * The skill invoked by this assistant turn, if any (`input.skill` on a
     * `Skill` tool_use block — field name confirmed against the real corpus,
     * NOT `input.command`). A message invokes at most one skill in practice;
     * the first match wins.
     */
    private function extractSkillName(array $content): ?string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'Skill') {
                $skill = $block['input']['skill'] ?? null;
                if (is_string($skill) && $skill !== '') {
                    return $skill;
                }
            }
        }
        return null;
    }

    /**
     * Read the sidecar `agent-<id>.meta.json` Claude Code writes next to every
     * subagent transcript: `{agentType, description, toolUseId, spawnDepth}`.
     * Missing or malformed sidecar returns nulls rather than throwing — the
     * scan must never fail on this, and the caller renders null `subagent_type`
     * as an explicit "general-purpose (unspecified)" bucket rather than
     * dropping the row.
     *
     * @return array{subagent_type: ?string, spawn_depth: ?int}
     */
    private function readSubagentSidecar(string $filePath): array
    {
        $none = ['subagent_type' => null, 'spawn_depth' => null];

        $sidecarPath = preg_replace('/\.jsonl$/', '.meta.json', $filePath);
        if (!is_string($sidecarPath) || !is_file($sidecarPath) || !is_readable($sidecarPath)) {
            return $none;
        }

        $data = json_decode((string) @file_get_contents($sidecarPath), true);
        if (!is_array($data)) {
            Log::warning("SessionScanner: malformed subagent sidecar {$sidecarPath}");
            return $none;
        }

        $agentType = $data['agentType'] ?? null;
        $spawnDepth = $data['spawnDepth'] ?? null;

        return [
            'subagent_type' => (is_string($agentType) && $agentType !== '') ? $agentType : null,
            'spawn_depth' => is_numeric($spawnDepth) ? (int) $spawnDepth : null,
        ];
    }

    /**
     * Strip XML tags from text, keeping inner content.
     */
    private function stripXmlTags(string $text): string
    {
        return preg_replace('/<[^>]+>/', '', $text) ?? $text;
    }

    /**
     * Truncate text at a word boundary.
     */
    private function truncateWordBoundary(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);

        // Find last space within the truncated string
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            return mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated;
    }

    /**
     * Count fork points: parent with 2+ non-sidechain children where
     * gap between sorted timestamps >= 5 minutes.
     */
    private function countForks(array $parentChildMap): int
    {
        $forkCount = 0;

        foreach ($parentChildMap as $children) {
            if (count($children) < 2) {
                continue;
            }

            sort($children);

            // Check if any gap between consecutive children >= 5 minutes
            for ($i = 1; $i < count($children); $i++) {
                $prev = strtotime($children[$i - 1]);
                $curr = strtotime($children[$i]);
                if ($prev && $curr && ($curr - $prev) >= 300) {
                    $forkCount++;
                    break; // Count each fork point once
                }
            }
        }

        return $forkCount;
    }

    /**
     * Mark sessions whose files no longer exist on disk as missing.
     *
     * Soft delete, NOT a delete: ~/.claude/projects rotates, and the DB is the
     * only surviving copy once a .jsonl is gone. Rows keep serving search,
     * analytics and recaps.
     *
     * @param string[] $currentFilePaths All currently discovered file paths
     */
    private function removeOrphanedSessions(array $currentFilePaths): int
    {
        if (empty($currentFilePaths)) {
            // Don't mark everything if we found no files — something may be wrong
            return 0;
        }

        $allDbPaths = DB::table('sessions.sessions')
            ->whereNull('file_missing_at')
            ->pluck('file_path')
            ->toArray();
        $currentSet = array_flip($currentFilePaths);
        $orphans = [];

        foreach ($allDbPaths as $dbPath) {
            if (!isset($currentSet[$dbPath])) {
                $orphans[] = $dbPath;
            }
        }

        if (empty($orphans)) {
            return 0;
        }

        return $this->markSessionsMissing($orphans);
    }

    /**
     * Stamp file_missing_at on the given file paths. Returns rows newly marked.
     *
     * @param string[] $filePaths
     */
    private function markSessionsMissing(array $filePaths): int
    {
        $marked = 0;

        foreach (array_chunk($filePaths, 100) as $chunk) {
            $marked += DB::table('sessions.sessions')
                ->whereIn('file_path', $chunk)
                ->whereNull('file_missing_at')
                ->update(['file_missing_at' => now()]);
        }

        return $marked;
    }

    /**
     * Mark orphaned sessions for a specific project as missing (soft delete).
     */
    private function removeOrphanedSessionsForProject(int $projectId, array $currentFilePaths): int
    {
        $dbPaths = DB::table('sessions.sessions')
            ->where('project_id', $projectId)
            ->whereNull('file_missing_at')
            ->pluck('file_path')
            ->toArray();

        $currentSet = array_flip($currentFilePaths);
        $orphans = [];

        foreach ($dbPaths as $dbPath) {
            if (!isset($currentSet[$dbPath])) {
                $orphans[] = $dbPath;
            }
        }

        if (empty($orphans)) {
            return 0;
        }

        return $this->markSessionsMissing($orphans);
    }

    /**
     * Format a PHP array as a Postgres TEXT[] literal.
     */
    private function formatPgArray(array $values): string
    {
        $escaped = array_map(function (string $val) {
            $val = str_replace('\\', '\\\\', $val);
            $val = str_replace('"', '\\"', $val);
            return '"' . $val . '"';
        }, $values);

        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * Record scan start in scan_history.
     */
    private function startScanHistory(): int
    {
        return DB::table('sessions.scan_history')->insertGetId([
            'started_at' => now(),
            'status' => 'running',
        ]);
    }

    /**
     * Record scan completion in scan_history.
     */
    private function completeScanHistory(int $scanId, string $status, array $totals, ?string $error = null): void
    {
        DB::table('sessions.scan_history')
            ->where('id', $scanId)
            ->update([
                'completed_at' => now(),
                'status' => $status,
                'files_scanned' => $totals['added'] + $totals['updated'] + $totals['skipped'],
                'files_added' => $totals['added'],
                'files_updated' => $totals['updated'],
                'files_removed' => $totals['removed'],
                'error_message' => $error,
            ]);
    }
}
