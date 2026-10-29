<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Task;
use App\Services\RemoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteQuickNoteToNoteTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStorage = sys_get_temp_dir() . '/jarvis-test-storage-' . uniqid();
        mkdir($this->tempStorage . '/app', 0777, true);
        $this->app->useStoragePath($this->tempStorage);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempStorage . '/app/*') ?: []);
        @rmdir($this->tempStorage . '/app');
        @rmdir($this->tempStorage);
        parent::tearDown();
    }

    private function fakePullWithNote(): void
    {
        config(['services.jarvis_remote.url' => 'https://remote.test']);
        config(['services.jarvis_remote.sync_key' => 'test-key']);

        Http::fake([
            'https://remote.test/*' => Http::response(['data' => [
                'tasks' => [], 'daily_plans' => [], 'interrupts' => [],
                'quick_notes' => [[
                    'id' => 42, 'project_id' => null, 'body' => 'random shower thought',
                    'type' => 'note', 'priority' => 'medium', 'context' => null,
                ]],
            ]]),
        ]);
    }

    public function test_type_note_becomes_local_note_not_task(): void
    {
        $this->fakePullWithNote();
        app(RemoteSyncService::class)->pull();

        $note = Note::first();
        $this->assertNotNull($note);
        $this->assertSame('random shower thought', $note->body);
        $this->assertSame('remote', $note->source->value);
        $this->assertSame('42', (string) $note->remote_id);
        $this->assertSame('inbox', $note->status->value);
        $this->assertSame(0, Task::count());
    }

    public function test_resync_is_idempotent(): void
    {
        $this->fakePullWithNote();
        app(RemoteSyncService::class)->pull();
        $this->fakePullWithNote();
        app(RemoteSyncService::class)->pull();

        $this->assertSame(1, Note::count());
    }
}
