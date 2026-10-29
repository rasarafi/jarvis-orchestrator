<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\RemoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteSyncPushTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        // Redirect storage_path() to an isolated temp directory so sync
        // watermark files written during tests never touch the real
        // storage/app/sync_last_{push,pull}.txt used by the live app.
        $this->tempStorage = sys_get_temp_dir() . '/jarvis-test-storage-' . uniqid();
        mkdir($this->tempStorage . '/app', 0777, true);
        $this->app->useStoragePath($this->tempStorage);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempStorage);
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }

    public function test_push_includes_project_group_key(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        Http::fake([
            'https://remote.test/api/sync/push' => Http::response(['data' => ['affected' => 1]]),
        ]);

        Project::create([
            'slug' => 'push-test',
            'name' => 'Push Test',
            'purpose' => 'test',
            'stack' => ['php'],
            'local_path' => '/tmp/x',
            'status' => 'active',
            'group_key' => 'mfb',
        ]);

        app(RemoteSyncService::class)->push();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/api/sync/push')) {
                return false;
            }
            $body = $request->data();
            if (($body['table'] ?? '') !== 'projects') {
                return false; // only match projects table
            }
            return ($body['records'][0]['group_key'] ?? null) === 'mfb';
        });
    }

    public function test_push_includes_soft_deleted_tasks(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        Http::fake([
            'https://remote.test/api/sync/push' => Http::response(['data' => ['affected' => 1]]),
        ]);

        $project = \App\Models\Project::create([
            'slug' => 'del-push-test', 'name' => 'DP', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);
        $task = \App\Models\Task::create([
            'project_id' => $project->id, 'title' => 'deleted task',
            'status' => 'todo', 'priority' => 'medium',
        ]);
        $task->delete();

        app(RemoteSyncService::class)->push();

        Http::assertSent(function ($request) use ($task) {
            $body = $request->data();
            if (($body['table'] ?? '') !== 'tasks') {
                return false;
            }
            $row = collect($body['records'])->firstWhere('id', $task->id);
            return $row !== null && !empty($row['deleted_at']);
        });
    }

    public function test_push_excludes_archived_tasks(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        // The remote `tasks` table has no `archived_at` column, and its
        // SyncService::push() filters incoming records to $fillable — so an
        // archived task would land on the phone looking like open work.
        Http::fake([
            'https://remote.test/api/sync/push' => Http::response(['data' => ['affected' => 1]]),
        ]);

        $project = \App\Models\Project::create([
            'slug' => 'archive-push-test', 'name' => 'AP', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);
        $open = \App\Models\Task::create([
            'project_id' => $project->id, 'title' => 'still open',
            'status' => 'todo', 'priority' => 'medium',
        ]);
        $archived = \App\Models\Task::create([
            'project_id' => $project->id, 'title' => 'archived task',
            'status' => 'todo', 'priority' => 'medium', 'archived_at' => now(),
        ]);

        app(\App\Services\RemoteSyncService::class)->push();

        Http::assertSent(function ($request) use ($open, $archived) {
            $body = $request->data();
            if (($body['table'] ?? '') !== 'tasks') {
                return false;
            }
            $ids = collect($body['records'])->pluck('id');
            return $ids->contains($open->id) && !$ids->contains($archived->id);
        });
    }

    public function test_push_watermark_is_run_start_not_completion(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        $start = \Illuminate\Support\Carbon::parse('2026-07-06 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($start);

        \App\Models\Project::create([
            'slug' => 'watermark-test', 'name' => 'WM', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);

        // Simulate time passing DURING the push: the fake advances the clock when the request lands.
        \Illuminate\Support\Facades\Http::fake(function () use ($start) {
            \Illuminate\Support\Carbon::setTestNow($start->copy()->addMinutes(10));
            return \Illuminate\Support\Facades\Http::response(['data' => ['affected' => 1]]);
        });

        app(\App\Services\RemoteSyncService::class)->push();

        $saved = json_decode(file_get_contents(storage_path('app/sync_last_push_tables.json')), true);
        $projectsWatermark = \Illuminate\Support\Carbon::parse($saved['projects']);
        $this->assertTrue(
            $projectsWatermark->equalTo($start),
            "watermark {$projectsWatermark} should equal run start {$start}"
        );

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_one_table_failure_does_not_block_other_tables_watermark(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        // A task with a NULL source 500s on the remote (NOT NULL column there).
        // Before the per-table watermark split, this froze projects/plans/
        // interrupts/context too, even though they push cleanly on their own.
        Http::fake([
            'https://remote.test/api/sync/push' => Http::sequence()
                ->push(['data' => ['affected' => 1]]) // projects
                ->push('Server Error', 500) // tasks
                ->push(['data' => ['affected' => 1]]) // daily_plans
                ->push(['data' => ['affected' => 1]]) // interrupts
                ->push(['data' => ['affected' => 1]]), // project_context
        ]);

        $project = \App\Models\Project::create([
            'slug' => 'partial-fail-test', 'name' => 'PF', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);
        \App\Models\Task::create([
            'project_id' => $project->id, 'title' => 'poison row',
            'status' => 'todo', 'priority' => 'medium',
        ]);
        \App\Models\DailyPlan::factory()->create(['date' => now()->toDateString()]);

        $results = app(\App\Services\RemoteSyncService::class)->push();

        $this->assertSame('ok', $results['projects']['status']);
        $this->assertSame('error', $results['tasks']['status']);
        $this->assertStringContainsString('500', $results['tasks']['error']);
        $this->assertSame('ok', $results['daily_plans']['status']);

        $saved = json_decode(file_get_contents(storage_path('app/sync_last_push_tables.json')), true);
        $this->assertGreaterThan(
            2020,
            \Illuminate\Support\Carbon::parse($saved['projects'])->year,
            'projects watermark should advance to run start despite tasks failing'
        );
        $this->assertGreaterThan(
            2020,
            \Illuminate\Support\Carbon::parse($saved['daily_plans'])->year,
            'daily_plans watermark should advance to run start despite tasks failing'
        );
        $this->assertSame(
            2020,
            \Illuminate\Support\Carbon::parse($saved['tasks'])->year,
            'tasks watermark must NOT advance — it failed, must retry next run'
        );
    }
}
