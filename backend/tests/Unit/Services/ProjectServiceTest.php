<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProjectService::class);
    }

    public function test_find_by_path_returns_project(): void
    {
        Project::factory()->withPath('/home/dev/projects/alpha')->create();

        $found = $this->service->findByPath('/home/dev/projects/alpha');

        $this->assertNotNull($found);
        $this->assertEquals('/home/dev/projects/alpha', $found->local_path);
    }

    public function test_find_by_path_returns_null_for_miss(): void
    {
        $this->assertNull($this->service->findByPath('/nonexistent'));
    }

    public function test_find_by_slug_returns_project(): void
    {
        Project::factory()->create(['slug' => 'test-project']);

        $found = $this->service->findBySlug('test-project');

        $this->assertNotNull($found);
        $this->assertEquals('test-project', $found->slug);
    }

    public function test_get_all_projects_ordered_by_last_session(): void
    {
        $old = Project::factory()->create(['last_session_at' => now()->subDays(5)]);
        $recent = Project::factory()->create(['last_session_at' => now()]);

        $projects = $this->service->getAllProjects();

        $this->assertEquals($recent->id, $projects->first()->id);
    }

    public function test_get_active_projects_excludes_paused(): void
    {
        Project::factory()->create(['status' => 'active']);
        Project::factory()->paused()->create();

        $active = $this->service->getActiveProjects();

        $this->assertCount(1, $active);
    }

    public function test_update_last_session_sets_timestamp(): void
    {
        $project = Project::factory()->create(['last_session_at' => null]);

        $this->service->updateLastSession($project);

        $this->assertNotNull($project->fresh()->last_session_at);
    }

    public function test_create_project_generates_slug(): void
    {
        $project = $this->service->createProject([
            'name' => 'My Cool Project',
            'purpose' => 'Testing',
            'local_path' => '/home/dev/projects/my-cool-project',
            'stack' => ['php' => '8.4'],
        ]);

        $this->assertEquals('my-cool-project', $project->slug);
    }

    public function test_get_dormant_returns_old_active_projects(): void
    {
        Project::factory()->create([
            'status' => 'active',
            'last_session_at' => now()->subDays(15),
        ]);

        $dormant = $this->service->getDormantProjects();

        $this->assertCount(1, $dormant);
    }

    public function test_get_dormant_excludes_recent_projects(): void
    {
        Project::factory()->create([
            'status' => 'active',
            'last_session_at' => now()->subDays(3),
        ]);

        $dormant = $this->service->getDormantProjects();

        $this->assertCount(0, $dormant);
    }

    public function test_get_dormant_excludes_paused_projects(): void
    {
        Project::factory()->paused()->create([
            'last_session_at' => now()->subDays(20),
        ]);

        $dormant = $this->service->getDormantProjects();

        $this->assertCount(0, $dormant);
    }

    public function test_get_dormant_excludes_never_touched_projects(): void
    {
        Project::factory()->create([
            'status' => 'active',
            'last_session_at' => null,
        ]);

        $dormant = $this->service->getDormantProjects();

        $this->assertCount(0, $dormant);
    }

    public function test_get_dormant_respects_custom_days(): void
    {
        Project::factory()->create([
            'status' => 'active',
            'last_session_at' => now()->subDays(5),
        ]);

        $this->assertCount(0, $this->service->getDormantProjects(10));
        $this->assertCount(1, $this->service->getDormantProjects(3));
    }
}
