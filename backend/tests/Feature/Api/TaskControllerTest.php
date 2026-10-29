<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_open_tasks(): void
    {
        $project = Project::factory()->create();
        $open = Task::factory()->create(['project_id' => $project->id, 'status' => 'todo']);
        $done = Task::factory()->done()->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/tasks');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    public function test_index_filters_by_project(): void
    {
        $p1 = Project::factory()->create();
        $p2 = Project::factory()->create();
        Task::factory()->create(['project_id' => $p1->id]);
        Task::factory()->create(['project_id' => $p2->id]);

        $response = $this->getJson("/api/tasks?project={$p1->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_project_group(): void
    {
        $fa = Project::factory()->create(['group_key' => 'fa']);
        $mfb = Project::factory()->create(['group_key' => 'mfb']);
        $faTask = Task::factory()->create(['project_id' => $fa->id]);
        Task::factory()->create(['project_id' => $mfb->id]);
        Task::factory()->create(['project_id' => null]);

        $response = $this->getJson('/api/tasks?group=fa');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $faTask->id);
    }

    public function test_index_rejects_unknown_group(): void
    {
        $this->getJson('/api/tasks?group=nope')->assertUnprocessable();
    }

    public function test_index_filters_done_by_completed_within(): void
    {
        $project = Project::factory()->create();
        $recent = Task::factory()->done()->create(['project_id' => $project->id]);
        $old = Task::factory()->done()->create([
            'project_id' => $project->id,
            'completed_at' => now()->subDays(10),
        ]);

        $response = $this->getJson('/api/tasks?status=done&completed_within=7d');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_index_rejects_unknown_completed_within(): void
    {
        $this->getJson('/api/tasks?completed_within=90d')->assertUnprocessable();
    }

    public function test_index_sorts_done_by_completed_at_desc(): void
    {
        $project = Project::factory()->create();
        $older = Task::factory()->done()->create([
            'project_id' => $project->id,
            'completed_at' => now()->subDays(2),
        ]);
        $newer = Task::factory()->done()->create([
            'project_id' => $project->id,
            'completed_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/tasks?status=done&sort=completed');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_index_filters_by_priority(): void
    {
        $project = Project::factory()->create();
        Task::factory()->urgent()->create(['project_id' => $project->id]);
        Task::factory()->low()->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/tasks?priority=urgent');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_task(): void
    {
        $project = Project::factory()->create();

        $response = $this->postJson('/api/tasks', [
            'project_id' => $project->id,
            'title' => 'Fix auth bug',
            'priority' => 'high',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Fix auth bug');

        $this->assertDatabaseHas('tasks', ['title' => 'Fix auth bug']);
    }

    public function test_store_creates_task_without_project(): void
    {
        $response = $this->postJson('/api/tasks', [
            'title' => 'Inbox task',
            'priority' => 'urgent',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Inbox task')
            ->assertJsonPath('data.project_id', null);

        $this->assertDatabaseHas('tasks', ['title' => 'Inbox task', 'project_id' => null]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/tasks', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_update_modifies_task(): void
    {
        $task = Task::factory()->create(['status' => 'todo']);

        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'status' => 'in_progress',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_destroy_deletes_task(): void
    {
        $task = Task::factory()->create();

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_urgent_returns_urgent_and_stale(): void
    {
        $project = Project::factory()->create();
        $urgent = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'todo',
            'deadline' => now()->addDays(2),
        ]);
        $normal = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'todo',
            'deadline' => now()->addDays(10),
        ]);

        $response = $this->getJson('/api/tasks/urgent');

        $response->assertOk()
            ->assertJsonStructure(['urgent', 'stale']);
    }

    public function test_status_can_be_set_to_review_and_filtered_by_it(): void
    {
        $project = \App\Models\Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'todo']);
        Task::factory()->create(['project_id' => $project->id, 'status' => 'todo']);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'review');

        $response = $this->getJson('/api/tasks?status=review')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($task->id, $response->json('data.0.id'));
    }

    public function test_update_status_to_done_sets_completed_at(): void
    {
        $task = Task::factory()->create(['status' => 'todo', 'completed_at' => null]);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertOk();

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_update_status_away_from_done_clears_completed_at(): void
    {
        $task = Task::factory()->create(['status' => 'done', 'completed_at' => now()]);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'todo'])->assertOk();

        $this->assertNull($task->fresh()->completed_at);
    }
}
