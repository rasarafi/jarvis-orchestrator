<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoPushAfterKiraSyncTest extends TestCase
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

    public function test_sync_tasks_triggers_remote_push_when_enabled(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);
        config(['services.jarvis_remote.auto_push' => true]);

        Http::fake(['https://remote.test/*' => Http::response(['data' => ['affected' => 0]])]);

        $dir = sys_get_temp_dir() . '/auto-push-test-' . uniqid();
        mkdir($dir . '/assistant-data', 0777, true);
        file_put_contents($dir . "/assistant-data/tasks.md", "# Tasks\n\n<!-- sync-manifest\n[{\"id\": null, \"title\": \"new one\", \"priority\": \"high\", \"status\": \"todo\"}]\n-->\n");

        $project = Project::create([
            'slug' => 'auto-push-test', 'name' => 'AP', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => $dir, 'status' => 'active',
        ]);

        $this->postJson("/api/projects/{$project->slug}/sync-tasks")->assertSuccessful();

        // Auto-push is dispatched via ->afterResponse(), which Laravel defers
        // to a terminating callback rather than running inline — trigger it
        // explicitly so the test genuinely proves the push still happens.
        $this->app->terminate();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/sync/push');
        });

        @unlink($dir . '/assistant-data/tasks.md');
        @rmdir($dir . '/assistant-data');
        @rmdir($dir);
    }

    public function test_sync_tasks_does_not_push_when_disabled(): void
    {
        config(['services.jarvis_remote.auto_push' => false]);
        Http::fake();

        $dir = sys_get_temp_dir() . '/auto-push-test2-' . uniqid();
        mkdir($dir . '/assistant-data', 0777, true);
        file_put_contents($dir . "/assistant-data/tasks.md", "# Tasks\n\n<!-- sync-manifest\n[]\n-->\n");

        $project = Project::create([
            'slug' => 'auto-push-test2', 'name' => 'AP2', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => $dir, 'status' => 'active',
        ]);

        $this->postJson("/api/projects/{$project->slug}/sync-tasks")->assertSuccessful();

        Http::assertNothingSent();

        @unlink($dir . '/assistant-data/tasks.md');
        @rmdir($dir . '/assistant-data');
        @rmdir($dir);
    }
}
