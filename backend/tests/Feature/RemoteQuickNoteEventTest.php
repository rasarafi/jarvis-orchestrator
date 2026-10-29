<?php

namespace Tests\Feature;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Services\RemoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteQuickNoteEventTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolated storage so watermark files never touch the live app's.
        $this->tempStorage = sys_get_temp_dir() . '/jarvis-test-storage-' . uniqid();
        mkdir($this->tempStorage . '/app', 0777, true);
        $this->app->useStoragePath($this->tempStorage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempStorage)) {
            foreach (scandir($this->tempStorage . '/app') ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    unlink($this->tempStorage . '/app/' . $item);
                }
            }
            rmdir($this->tempStorage . '/app');
            rmdir($this->tempStorage);
        }
        parent::tearDown();
    }

    public function test_quick_note_task_conversion_emits_remote_created_event(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        Http::fake([
            'https://remote.test/*' => Http::response(['data' => [
                'tasks' => [], 'daily_plans' => [], 'interrupts' => [],
                'quick_notes' => [[
                    'id' => 1, 'project_id' => null, 'body' => 'From phone',
                    'type' => 'task', 'priority' => 'high', 'context' => 'ctx',
                ]],
            ]]),
        ]);

        app(RemoteSyncService::class)->pull();

        $task = Task::where('source', 'remote')->first();
        $this->assertNotNull($task);
        $this->assertSame('From phone', $task->title);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'actor' => TaskActor::REMOTE->value,
            'event_type' => TaskEventType::CREATED->value,
        ]);
    }
}
