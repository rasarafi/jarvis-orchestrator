<?php

namespace App\Services;

use App\Enums\NoteSource;
use App\Enums\TaskActor;
use App\Enums\TaskSource;
use App\Models\DailyPlan;
use App\Models\Interrupt;
use App\Models\Project;
use App\Models\ProjectContext;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RemoteSyncService
{
    private string $baseUrl;
    private string $syncKey;

    public function __construct(
        private TaskService $taskService,
        private NoteService $noteService,
    ) {
        $this->baseUrl = rtrim(config('services.jarvis_remote.url'), '/');
        $this->syncKey = config('services.jarvis_remote.sync_key');
    }

    /**
     * Each table gets its own watermark, advanced only when that table's own
     * push succeeds. A single failing table (e.g. a schema mismatch) used to
     * freeze the watermark for every table via one shared `$allSucceeded`
     * flag — 45 days of pushes silently no-opped after one bad task row
     * (source=NULL, rejected by the remote's NOT NULL column) blocked
     * projects/plans/interrupts/context that were pushing fine.
     *
     * Returns `['status' => 'ok'|'error', 'affected' => int, 'error'?: string]`
     * per table — never collapses a failure to a bare `0`, which is
     * indistinguishable from "nothing to send".
     */
    public function push(): array
    {
        $startedAt = now();

        $results = [];
        $tables = [
            'projects' => Project::class,
            'tasks' => Task::class,
            'daily_plans' => DailyPlan::class,
            'interrupts' => Interrupt::class,
            'project_context' => ProjectContext::class,
        ];

        $watermarks = $this->getPushWatermarks();

        foreach ($tables as $tableName => $modelClass) {
            $since = $watermarks[$tableName] ?? Carbon::parse('2020-01-01');

            $query = $modelClass::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $query->withTrashed();
            }
            if ($modelClass === Task::class) {
                // The remote `tasks` table has no `archived_at` column — SyncService::push()
                // silently drops unknown fields via its $fillable filter, so an archived
                // task would land on the phone looking like open work. Keep it local
                // until the remote schema grows the column.
                $query->whereNull('archived_at');
            }
            $records = $query->where('updated_at', '>', $since)->get()->toArray();

            if (empty($records)) {
                $results[$tableName] = ['status' => 'ok', 'affected' => 0];
                $watermarks[$tableName] = $startedAt;
                continue;
            }

            try {
                $response = Http::withHeaders(['X-Sync-Key' => $this->syncKey])
                    ->timeout(30)
                    ->post("{$this->baseUrl}/api/sync/push", [
                        'table' => $tableName,
                        'records' => $records,
                    ]);
            } catch (\Throwable $e) {
                $results[$tableName] = ['status' => 'error', 'affected' => 0, 'error' => $e->getMessage()];
                Log::error("sync push: {$tableName} request failed: {$e->getMessage()}");
                continue;
            }

            if (!$response->successful()) {
                $error = "HTTP {$response->status()}: " . Str::limit(strip_tags($response->body()), 300);
                $results[$tableName] = ['status' => 'error', 'affected' => 0, 'error' => $error];
                Log::error("sync push: {$tableName} failed — {$error}");
                continue;
            }

            // Remote API wraps response as {data: {affected: N, message: ...}}
            $results[$tableName] = ['status' => 'ok', 'affected' => $response->json('data.affected', 0)];
            $watermarks[$tableName] = $startedAt;
        }

        $this->savePushWatermarks($watermarks);

        return $results;
    }

    public function pull(): array
    {
        $startedAt = now();

        $since = $this->getLastSyncTime('pull');

        $response = Http::withHeaders(['X-Sync-Key' => $this->syncKey])
            ->timeout(30)
            ->get("{$this->baseUrl}/api/sync/pull", ['since' => $since->toISOString()]);

        $body = $response->json();
        // Remote API wraps responses in {data: ...} — unwrap if present
        $data = $body['data'] ?? $body;
        $results = [];
        $failed = 0;

        // Upsert tasks modified remotely. Each record is isolated so one
        // poison row (e.g. a stale FK) can't abort the run and block the
        // rest of the tasks, the other tables, or the watermark advance.
        foreach ($data['tasks'] ?? [] as $taskData) {
            try {
                // Wrapped in its own transaction (savepoint, if nested) so a
                // poison row's failed query doesn't abort the whole
                // connection's transaction under Postgres, which would take
                // every subsequent record down with it.
                DB::transaction(function () use ($taskData) {
                    $existing = Task::withTrashed()->find($taskData['id']);
                    if ($existing) {
                        if (Carbon::parse($taskData['updated_at'])->greaterThan($existing->updated_at)) {
                            $existing->forceFill($taskData)->save();
                        }
                    } else {
                        (new Task())->forceFill($taskData)->save();
                    }
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error("sync pull: failed to apply tasks record {$taskData['id']}: {$e->getMessage()}");
            }
        }
        $results['tasks'] = count($data['tasks'] ?? []);

        // Upsert daily plans
        foreach ($data['daily_plans'] ?? [] as $planData) {
            try {
                DB::transaction(function () use ($planData) {
                    $existing = DailyPlan::find($planData['id']);
                    if ($existing) {
                        if (Carbon::parse($planData['updated_at'])->greaterThan($existing->updated_at)) {
                            $existing->forceFill($planData)->save();
                        }
                    } else {
                        (new DailyPlan())->forceFill($planData)->save();
                    }
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error("sync pull: failed to apply daily_plans record {$planData['id']}: {$e->getMessage()}");
            }
        }
        $results['daily_plans'] = count($data['daily_plans'] ?? []);

        // Upsert interrupts
        foreach ($data['interrupts'] ?? [] as $interruptData) {
            try {
                DB::transaction(function () use ($interruptData) {
                    $existing = Interrupt::find($interruptData['id']);
                    if ($existing) {
                        if (Carbon::parse($interruptData['updated_at'])->greaterThan($existing->updated_at)) {
                            $existing->forceFill($interruptData)->save();
                        }
                    } else {
                        (new Interrupt())->forceFill($interruptData)->save();
                    }
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error("sync pull: failed to apply interrupts record {$interruptData['id']}: {$e->getMessage()}");
            }
        }
        $results['interrupts'] = count($data['interrupts'] ?? []);

        // Process quick notes — convert ALL to tasks
        $noteIds = [];
        foreach ($data['quick_notes'] ?? [] as $note) {
            try {
                DB::transaction(function () use ($note) {
                    $this->processQuickNote($note);
                });
                $noteIds[] = $note['id'];
            } catch (\Throwable $e) {
                $failed++;
                Log::error("sync pull: failed to apply quick_notes record {$note['id']}: {$e->getMessage()}");
            }
        }
        $results['quick_notes'] = count($noteIds);

        // Confirm processed notes
        if (!empty($noteIds)) {
            Http::withHeaders(['X-Sync-Key' => $this->syncKey])
                ->post("{$this->baseUrl}/api/sync/confirm", [
                    'quick_note_ids' => $noteIds,
                ]);
        }

        if ($failed > 0) {
            $results['failed'] = $failed;
        }

        if (array_sum([$results['tasks'], $results['daily_plans'], $results['interrupts'], $results['quick_notes']]) > 0) {
            $this->saveLastSyncTime('pull', $startedAt);
        }
        return $results;
    }

    /**
     * Disaster recovery: pull EVERYTHING from the remote, including projects
     * and project_context (which the regular pull() skips per the sync
     * protocol). Idempotent — existing records are updated only if the
     * remote version is newer.
     *
     * Use this after a local data-loss event (e.g. an accidental
     * `migrate:fresh` against the dev DB). The remote's regular `/api/sync/pull`
     * endpoint accepts an explicit `tables` query param for this purpose.
     */
    public function recoverFromRemote(): array
    {
        $tables = 'projects,project_context,tasks,daily_plans,interrupts';
        $since = Carbon::parse('2020-01-01')->toISOString();

        $response = Http::withHeaders(['X-Sync-Key' => $this->syncKey])
            ->timeout(60)
            ->get("{$this->baseUrl}/api/sync/pull", [
                'since' => $since,
                'tables' => $tables,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Remote pull failed: HTTP {$response->status()}");
        }

        $body = $response->json();
        $data = $body['data'] ?? $body;
        $results = [];

        $projectCols = $this->columnsFor(Project::class);
        $contextCols = $this->columnsFor(ProjectContext::class);
        $taskCols = $this->columnsFor(Task::class);
        $planCols = $this->columnsFor(DailyPlan::class);
        $interruptCols = $this->columnsFor(Interrupt::class);

        // Projects FIRST — tasks and project_context depend on them via FK.
        // Remote does not store local-only columns (`local_path`, `repo_url`, etc.),
        // so fill sensible defaults for NOT NULL columns the user can set later.
        foreach ($data['projects'] ?? [] as $projectData) {
            $projectData['local_path'] = $projectData['local_path'] ?? '';
            $this->upsertById(Project::class, $projectData, $projectCols);
        }
        $results['projects'] = count($data['projects'] ?? []);

        foreach ($data['project_context'] ?? [] as $contextData) {
            $this->upsertById(ProjectContext::class, $contextData, $contextCols);
        }
        $results['project_context'] = count($data['project_context'] ?? []);

        foreach ($data['tasks'] ?? [] as $taskData) {
            $this->upsertById(Task::class, $taskData, $taskCols);
        }
        $results['tasks'] = count($data['tasks'] ?? []);

        foreach ($data['daily_plans'] ?? [] as $planData) {
            $this->upsertById(DailyPlan::class, $planData, $planCols);
        }
        $results['daily_plans'] = count($data['daily_plans'] ?? []);

        foreach ($data['interrupts'] ?? [] as $interruptData) {
            $this->upsertById(Interrupt::class, $interruptData, $interruptCols);
        }
        $results['interrupts'] = count($data['interrupts'] ?? []);

        return $results;
    }

    /**
     * Upsert a model by id, keeping the newer `updated_at` version.
     * Filters out columns that don't exist on the local table (e.g. remote
     * tables have `deleted_at` for soft deletes, but this app's models don't
     * use SoftDeletes — attempting to save such fields would fail SQL).
     *
     * Callers pass the pre-computed column allowlist so Schema is introspected
     * once per table, not once per row.
     */
    private function upsertById(string $modelClass, array $row, array $validColumns): void
    {
        $filtered = array_intersect_key($row, array_flip($validColumns));

        $existing = $modelClass::find($filtered['id'] ?? null);
        if ($existing) {
            $remoteUpdatedAt = isset($filtered['updated_at']) ? Carbon::parse($filtered['updated_at']) : null;
            if ($remoteUpdatedAt && $remoteUpdatedAt->greaterThan($existing->updated_at)) {
                $existing->forceFill($filtered)->save();
            }
            return;
        }
        (new $modelClass())->forceFill($filtered)->save();
    }

    private function columnsFor(string $modelClass): array
    {
        return \Illuminate\Support\Facades\Schema::getColumnListing((new $modelClass())->getTable());
    }

    private function processQuickNote(array $note): void
    {
        if (($note['type'] ?? null) === 'note') {
            $this->noteService->capture([
                'body' => $note['body'],
                'project_id' => $note['project_id'],
                'source' => NoteSource::REMOTE,
                'remote_id' => $note['id'],
            ]);
            return;
        }

        $this->taskService->createTask(null, [
            'title' => $note['body'],
            'project_id' => $note['project_id'],
            'description' => $note['context'] ?? null,
            'priority' => $note['priority'] ?? 'medium',
            'status' => 'todo',
            'source' => TaskSource::REMOTE,
        ], TaskActor::REMOTE);
    }

    private function getLastSyncTime(string $direction): Carbon
    {
        $file = storage_path("app/sync_last_{$direction}.txt");
        if (file_exists($file)) {
            try {
                return Carbon::parse(file_get_contents($file));
            } catch (\Throwable $e) {
                Log::error("sync {$direction}: corrupt watermark file, falling back to epoch: {$e->getMessage()}");
            }
        }
        // Falling back to the epoch is safe — re-sending already-synced
        // records is idempotent (last-write-wins upserts).
        return Carbon::parse('2020-01-01');
    }

    private function saveLastSyncTime(string $direction, Carbon $timestamp): void
    {
        file_put_contents(storage_path("app/sync_last_{$direction}.txt"), $timestamp->toISOString(), LOCK_EX);
    }

    /**
     * @return array<string, Carbon> table name => last successful push time
     */
    private function getPushWatermarks(): array
    {
        $file = storage_path('app/sync_last_push_tables.json');

        if (!file_exists($file)) {
            // First run after the per-table split: seed every table from the
            // old single-file watermark instead of the epoch, so we don't
            // re-push everything that was already synced before this change.
            $legacy = $this->getLastSyncTime('push');

            return array_fill_keys(
                ['projects', 'tasks', 'daily_plans', 'interrupts', 'project_context'],
                $legacy,
            );
        }

        try {
            $raw = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            return array_map(fn (string $iso) => Carbon::parse($iso), $raw);
        } catch (\Throwable $e) {
            Log::error("sync push: corrupt per-table watermark file, falling back to epoch: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * @param array<string, Carbon> $watermarks
     */
    private function savePushWatermarks(array $watermarks): void
    {
        $encoded = array_map(fn (Carbon $t) => $t->toISOString(), $watermarks);
        file_put_contents(
            storage_path('app/sync_last_push_tables.json'),
            json_encode($encoded, JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }
}
