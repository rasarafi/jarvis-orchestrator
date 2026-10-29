<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Services\KiraSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KiraSyncDeleteTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/kira-sync-test-' . uniqid();
        mkdir($this->dir . '/assistant-data', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/assistant-data/tasks.md');
        @rmdir($this->dir . '/assistant-data');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function makeProject(): Project
    {
        return Project::create([
            'slug' => 'kira-del-test', 'name' => 'KD', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => $this->dir, 'status' => 'active',
        ]);
    }

    private function writeTasksMd(array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents(
            $this->dir . '/assistant-data/tasks.md',
            "# Tasks\n\n## High Priority\n- [ ] Some task\n\n<!-- sync-manifest\n{$json}\n-->\n"
        );
    }

    public function test_trashed_task_gets_deleted_flag_in_manifest(): void
    {
        $project = $this->makeProject();
        $task = Task::create([
            'project_id' => $project->id, 'title' => 'was deleted centrally',
            'status' => 'todo', 'priority' => 'high',
        ]);
        $task->delete();

        $this->writeTasksMd([
            ['id' => $task->id, 'title' => 'was deleted centrally', 'priority' => 'high', 'status' => 'todo'],
        ]);

        $result = app(KiraSyncService::class)->importFromProject($project->fresh());

        $this->assertSame(1, $result['deleted']);
        $content = file_get_contents($this->dir . '/assistant-data/tasks.md');
        preg_match('/<!-- sync-manifest\s*\n(.*?)-->/s', $content, $m);
        $manifest = json_decode(trim($m[1]), true);
        $this->assertTrue($manifest[0]['deleted']);
    }

    public function test_deleted_flagged_entry_is_not_recreated(): void
    {
        $project = $this->makeProject();

        $this->writeTasksMd([
            ['id' => null, 'title' => 'ghost task', 'priority' => 'high', 'status' => 'todo', 'deleted' => true],
        ]);

        $result = app(KiraSyncService::class)->importFromProject($project->fresh());

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Task::where('title', 'ghost task')->count());
    }

    public function test_missing_id_entry_gets_deleted_flag(): void
    {
        $project = $this->makeProject();
        $vanishedId = (string) \Illuminate\Support\Str::uuid();

        $this->writeTasksMd([
            ['id' => $vanishedId, 'title' => 'hard-vanished', 'priority' => 'low', 'status' => 'todo'],
        ]);

        $result = app(KiraSyncService::class)->importFromProject($project->fresh());

        $this->assertSame(1, $result['deleted']);
    }

    public function test_tombstone_entry_for_live_task_is_revived_and_updated(): void
    {
        $project = $this->makeProject();
        $task = Task::create([
            'project_id' => $project->id, 'title' => 'came back alive',
            'status' => 'todo', 'priority' => 'medium',
        ]);
        // Task is NOT trashed — it came back alive via a remote edit after
        // Kira flagged the manifest entry deleted but before the markdown
        // line was removed.

        $this->writeTasksMd([
            ['id' => $task->id, 'title' => 'came back alive', 'priority' => 'high', 'status' => 'todo', 'deleted' => true],
        ]);

        $result = app(KiraSyncService::class)->importFromProject($project->fresh());

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['updated']);

        $content = file_get_contents($this->dir . '/assistant-data/tasks.md');
        preg_match('/<!-- sync-manifest\s*\n(.*?)-->/s', $content, $m);
        $manifest = json_decode(trim($m[1]), true);
        $this->assertArrayNotHasKey('deleted', $manifest[0]);

        $this->assertSame('high', $task->fresh()->priority instanceof \BackedEnum ? $task->fresh()->priority->value : $task->fresh()->priority);
    }
}
