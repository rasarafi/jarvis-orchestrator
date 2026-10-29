<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Services\RemoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteSyncPullTest extends TestCase
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

    public function test_pull_soft_deletes_task_deleted_on_remote(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        $project = Project::create([
            'slug' => 'del-pull-test', 'name' => 'DPull', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);
        $task = Task::create([
            'project_id' => $project->id, 'title' => 'remote deleted me',
            'status' => 'todo', 'priority' => 'medium',
        ]);

        Http::fake([
            'https://remote.test/api/sync/pull*' => Http::response(['data' => [
                'tasks' => [array_merge($task->toArray(), [
                    'updated_at' => now()->addMinute()->toISOString(),
                    'deleted_at' => now()->addMinute()->toISOString(),
                ])],
                'daily_plans' => [], 'interrupts' => [], 'quick_notes' => [],
            ]]),
        ]);

        app(RemoteSyncService::class)->pull();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_pull_is_idempotent_for_already_deleted_task(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        $project = Project::create([
            'slug' => 'idem-pull-test', 'name' => 'IdempPull', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => '/tmp/x', 'status' => 'active',
        ]);
        $task = Task::create([
            'project_id' => $project->id, 'title' => 'already deleted',
            'status' => 'todo', 'priority' => 'medium',
        ]);

        // Soft-delete locally
        $task->delete();

        Http::fake([
            'https://remote.test/api/sync/pull*' => Http::response(['data' => [
                'tasks' => [array_merge($task->toArray(), [
                    'updated_at' => now()->addMinutes(2)->toISOString(),
                    'deleted_at' => now()->addMinutes(2)->toISOString(),
                ])],
                'daily_plans' => [], 'interrupts' => [], 'quick_notes' => [],
            ]]),
        ]);

        // Should not throw exception
        app(RemoteSyncService::class)->pull();

        // Task should still be soft-deleted, not duplicated
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertEquals(1, Task::withTrashed()->where('id', $task->id)->count());
    }

    public function test_pull_isolates_poison_record_and_still_applies_good_row(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        $goodTaskId = (string) \Illuminate\Support\Str::uuid();
        $badTaskId = (string) \Illuminate\Support\Str::uuid();
        $missingProjectId = (string) \Illuminate\Support\Str::uuid();

        Http::fake([
            'https://remote.test/api/sync/pull*' => Http::response(['data' => [
                'tasks' => [
                    [
                        'id' => $badTaskId,
                        'project_id' => $missingProjectId, // FK violation — no such project
                        'title' => 'poison row',
                        'status' => 'todo',
                        'priority' => 'medium',
                        'updated_at' => now()->toISOString(),
                        'created_at' => now()->toISOString(),
                    ],
                    [
                        'id' => $goodTaskId,
                        'project_id' => null,
                        'title' => 'good row',
                        'status' => 'todo',
                        'priority' => 'medium',
                        'updated_at' => now()->toISOString(),
                        'created_at' => now()->toISOString(),
                    ],
                ],
                'daily_plans' => [], 'interrupts' => [], 'quick_notes' => [],
            ]]),
        ]);

        $results = app(RemoteSyncService::class)->pull();

        $this->assertDatabaseHas('tasks', ['id' => $goodTaskId, 'title' => 'good row']);
        $this->assertDatabaseMissing('tasks', ['id' => $badTaskId]);
        $this->assertSame(1, $results['failed']);
        $this->assertSame(2, $results['tasks']);
    }

    public function test_pull_watermark_is_run_start_not_completion(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        $start = \Illuminate\Support\Carbon::parse('2026-07-06 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($start);

        // Simulate time passing DURING the pull: the fake advances the clock when the request lands.
        Http::fake(function () use ($start) {
            \Illuminate\Support\Carbon::setTestNow($start->copy()->addMinutes(10));
            return Http::response(['data' => [
                'tasks' => [], 'daily_plans' => [], 'interrupts' => [],
                'quick_notes' => [[
                    'id' => 1, 'project_id' => null, 'body' => 'note',
                    'type' => 'note', 'priority' => 'medium', 'context' => null,
                ]],
            ]]);
        });

        app(RemoteSyncService::class)->pull();

        $saved = \Illuminate\Support\Carbon::parse(
            file_get_contents(storage_path('app/sync_last_pull.txt'))
        );
        $this->assertTrue($saved->equalTo($start), "watermark {$saved} should equal run start {$start}");

        \Illuminate\Support\Carbon::setTestNow();
    }
}
