<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_all_projects_with_task_counts(): void
    {
        $project = Project::factory()->create();
        Task::factory()->count(3)->create(['project_id' => $project->id, 'status' => 'todo']);
        Task::factory()->done()->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/projects');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_project_detail(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        Task::factory()->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/projects/test-project');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'test-project');
    }

    public function test_show_returns_404_for_invalid_slug(): void
    {
        $response = $this->getJson('/api/projects/nonexistent');

        $response->assertNotFound();
    }

    public function test_update_modifies_project(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->patchJson('/api/projects/test-project', [
            'current_focus' => 'Fixing auth system',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.current_focus', 'Fixing auth system');
    }
}
