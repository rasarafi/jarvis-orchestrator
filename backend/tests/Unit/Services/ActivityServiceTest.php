<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Services\ActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityService::class);
    }

    public function test_log_commit_creates_entry(): void
    {
        $project = Project::factory()->create();

        $entry = $this->service->logCommit($project, 'fix: login bug', ['hash' => 'abc123']);

        $this->assertEquals('commit', $entry->type);
        $this->assertEquals('fix: login bug', $entry->message);
        $this->assertEquals('abc123', $entry->metadata['hash']);
    }

    public function test_log_session_start(): void
    {
        $project = Project::factory()->create();

        $entry = $this->service->logSessionStart($project, 'session-xyz');

        $this->assertEquals('session_start', $entry->type);
    }

    public function test_log_system_has_no_project(): void
    {
        $entry = $this->service->logSystem('System rebooted');

        $this->assertNull($entry->project_id);
        $this->assertEquals('system', $entry->type);
    }

    public function test_get_recent_activity_respects_limit(): void
    {
        $project = Project::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->service->logCommit($project, "commit $i");
        }

        $recent = $this->service->getRecentActivity(5);

        $this->assertCount(5, $recent);
    }

    public function test_get_project_activity_scoped(): void
    {
        $p1 = Project::factory()->create();
        $p2 = Project::factory()->create();
        $this->service->logCommit($p1, 'p1 commit');
        $this->service->logCommit($p2, 'p2 commit');

        $activity = $this->service->getProjectActivity($p1);

        $this->assertCount(1, $activity);
        $this->assertEquals('p1 commit', $activity->first()->message);
    }
}
