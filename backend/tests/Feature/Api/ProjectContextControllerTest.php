<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectContextControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_empty_when_no_context(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->getJson('/api/projects/test-project/context');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_returns_all_sections(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'architecture', 'content' => '## Stack']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'features', 'content' => '## Features']);

        $response = $this->getJson('/api/projects/test-project/context');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_section_query_param(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'architecture', 'content' => '## Stack']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'features', 'content' => '## Features']);

        $response = $this->getJson('/api/projects/test-project/context?section=architecture');

        $response->assertOk()
            ->assertJsonPath('data.section', 'architecture');
    }

    public function test_index_returns_422_for_invalid_section_param(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->getJson('/api/projects/test-project/context?section=invalid');

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'valid']);
    }

    public function test_show_returns_single_section(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'todos', 'content' => '- Fix bug']);

        $response = $this->getJson('/api/projects/test-project/context/todos');

        $response->assertOk()
            ->assertJsonPath('data.section', 'todos')
            ->assertJsonPath('data.content', '- Fix bug');
    }

    public function test_show_returns_null_for_unpopulated_section(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->getJson('/api/projects/test-project/context/architecture');

        $response->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_show_returns_422_for_invalid_section(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->getJson('/api/projects/test-project/context/nonsense');

        $response->assertStatus(422);
    }

    public function test_show_returns_404_for_invalid_project(): void
    {
        $response = $this->getJson('/api/projects/nonexistent/context/architecture');

        $response->assertNotFound();
    }

    public function test_update_creates_new_section(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->putJson('/api/projects/test-project/context/architecture', [
            'content' => '## Laravel + React',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.section', 'architecture')
            ->assertJsonPath('data.content', '## Laravel + React');

        $this->assertDatabaseCount('project_context', 1);
    }

    public function test_update_upserts_existing_section(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'architecture', 'content' => 'Old content']);

        $response = $this->putJson('/api/projects/test-project/context/architecture', [
            'content' => 'New content',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.content', 'New content');

        $this->assertDatabaseCount('project_context', 1);
    }

    public function test_update_validates_content_required(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);

        $response = $this->putJson('/api/projects/test-project/context/architecture', []);

        $response->assertStatus(422);
    }

    public function test_cross_project_returns_section_across_projects(): void
    {
        $p1 = Project::factory()->create(['slug' => 'project-a', 'name' => 'Project A']);
        $p2 = Project::factory()->create(['slug' => 'project-b', 'name' => 'Project B']);
        ProjectContext::create(['project_id' => $p1->id, 'section' => 'todos', 'content' => 'Fix A']);
        ProjectContext::create(['project_id' => $p2->id, 'section' => 'todos', 'content' => 'Fix B']);
        ProjectContext::create(['project_id' => $p1->id, 'section' => 'architecture', 'content' => 'Stack A']);

        $response = $this->getJson('/api/context/todos');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_cross_project_returns_422_for_invalid_section(): void
    {
        $response = $this->getJson('/api/context/invalid');

        $response->assertStatus(422);
    }

    public function test_context_deleted_when_project_deleted(): void
    {
        $project = Project::factory()->create(['slug' => 'test-project']);
        ProjectContext::create(['project_id' => $project->id, 'section' => 'architecture', 'content' => 'Stack']);

        $project->delete();

        $this->assertDatabaseCount('project_context', 0);
    }
}
