<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRemoteCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jarvis_remote.url' => 'https://jarvis-remote.example.com']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

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

    public function test_push_sends_data_to_remote(): void
    {
        Project::factory()->create();

        Http::fake([
            '*/api/sync/push' => Http::response(['records_affected' => 1]),
        ]);

        $this->artisan('jarvis:sync-remote', ['direction' => 'push'])
            ->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/sync/push'));
    }

    public function test_push_failure_exits_non_zero_and_reports_the_table(): void
    {
        Project::factory()->create();

        Http::fake([
            '*/api/sync/push' => Http::response('Server Error', 500),
        ]);

        $this->artisan('jarvis:sync-remote', ['direction' => 'push'])
            ->assertFailed()
            ->expectsOutputToContain('projects: FAILED');
    }

    public function test_pull_fetches_and_confirms(): void
    {
        Http::fake([
            '*/api/sync/pull*' => Http::response([
                'tasks' => [],
                'quick_notes' => [],
                'daily_plans' => [],
                'interrupts' => [],
            ]),
        ]);

        $this->artisan('jarvis:sync-remote', ['direction' => 'pull'])
            ->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/sync/pull'));
    }

    public function test_both_direction_runs_push_and_pull(): void
    {
        Http::fake([
            '*/api/sync/push' => Http::response(['records_affected' => 0]),
            '*/api/sync/pull*' => Http::response([
                'tasks' => [],
                'quick_notes' => [],
                'daily_plans' => [],
                'interrupts' => [],
            ]),
        ]);

        $this->artisan('jarvis:sync-remote')
            ->assertSuccessful();
    }

    public function test_pull_converts_quick_note_without_project_to_task(): void
    {
        Http::fake([
            '*/api/sync/pull*' => Http::response(['data' => [
                'tasks' => [],
                'daily_plans' => [],
                'interrupts' => [],
                'quick_notes' => [
                    [
                        'id' => '019d-fake-note-1',
                        'project_id' => null,
                        'body' => 'Cognygen Resume',
                        'type' => 'task',
                        'priority' => 'urgent',
                        'context' => null,
                        'synced_at' => null,
                        'created_at' => now()->toISOString(),
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ]]),
            '*/api/sync/confirm' => Http::response(['data' => ['confirmed' => 1]]),
        ]);

        $this->artisan('jarvis:sync-remote', ['direction' => 'pull'])
            ->assertSuccessful();

        $this->assertDatabaseHas('tasks', [
            'title' => 'Cognygen Resume',
            'project_id' => null,
            'priority' => 'urgent',
            'source' => 'remote',
        ]);
    }

    public function test_pull_lands_note_type_quick_note_in_notes_inbox(): void
    {
        Http::fake([
            '*/api/sync/pull*' => Http::response(['data' => [
                'tasks' => [],
                'daily_plans' => [],
                'interrupts' => [],
                'quick_notes' => [
                    [
                        'id' => '019d-fake-note-2',
                        'project_id' => null,
                        'body' => 'New project: Hiba — design demo done',
                        'type' => 'note',
                        'priority' => null,
                        'context' => 'See WhatsApp notes',
                        'synced_at' => null,
                        'created_at' => now()->toISOString(),
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ]]),
            '*/api/sync/confirm' => Http::response(['data' => ['confirmed' => 1]]),
        ]);

        $this->artisan('jarvis:sync-remote', ['direction' => 'pull'])
            ->assertSuccessful();

        $this->assertDatabaseHas('notes', [
            'body' => 'New project: Hiba — design demo done',
            'project_id' => null,
            'source' => 'remote',
            'status' => 'inbox',
            'remote_id' => '019d-fake-note-2',
        ]);
        $this->assertDatabaseMissing('tasks', [
            'title' => 'New project: Hiba — design demo done',
        ]);
    }
}
